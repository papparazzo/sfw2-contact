<?php

/*
 *  Project:    springersinggemeinschaft_dev
 *
 *  Copyright (C) 2020 Stefan Paproth <pappi-@gmx.de>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see <http://www.gnu.org/licenses/agpl.txt>.
 *
 */

namespace SFW2\Guestbook\Controller;

use Exception;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SFW2\Core\HttpExceptions\HttpNotFound;
use SFW2\Core\HttpExceptions\HttpUnprocessableContent;
use SFW2\Core\Permission\AccessType;
use SFW2\Core\Permission\PermissionInterface;
use SFW2\Core\Utils\DateTimeHelper;
use SFW2\Core\Utils\Mailer;
use SFW2\Database\DatabaseInterface;
use SFW2\Routing\AbstractController;

use SFW2\Routing\HelperTraits\getRoutingDataTrait;
use SFW2\Routing\HelperTraits\getUrlTrait;
use SFW2\Routing\ResponseEngine;
use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;
use SFW2\Validator\Validators\IsAvailable;
use SFW2\Validator\Validators\IsEMailAddress;
use SFW2\Validator\Validators\IsTrue;


final class Guestbook extends AbstractController {

    use getUrlTrait;
    use getRoutingDataTrait;

    protected string $title;
    protected string $description;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly DateTimeHelper $dateTimeHelper,
        private readonly Mailer $mailer,
        private readonly PermissionInterface $permission
    ) {
        $this->title = 'Gästebuch';
        $this->description = 'Hier ist unser Gästebuch. Wenn Du magst dann lass einen Eintrag zurück.';
    }

    /**
     * @throws Exception
     */
    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        $pathId = $this->getPathId($request);
        $content = [
            'title' => $this->title,
            'description' => $this->description,
            'entries' => $this->getEntries(false, $this->getPathId($request)),
            'create_allowed' => $this->permission->checkPermission($pathId, 'create') !== AccessType::VORBIDDEN
        ];

        return $responseEngine->render($request, $content, "SFW2\\Guestbook\\Guestbook");
    }

    /**
     * @throws HttpUnprocessableContent
     * @throws HttpNotFound
     * @noinspection PhpUnused
     */
    public function unlockEntryByHash(Request $request, ResponseEngine $responseEngine): Response
    {
        // FIXME replace filter_input
        $hash = filter_input(INPUT_GET, 'hash', FILTER_VALIDATE_REGEXP, ["options" => ["regexp" => "/^[a-f0-9]{32}$/"]]);
        if($hash === false || is_null($hash)) {
            throw new HttpUnprocessableContent("invalid hash given");
        }

        $content = [
            'confirm' => false
        ];

        $stmt = "UPDATE `{TABLE_PREFIX}_guestbook` SET `Visible` = '1' WHERE `UnlockHash` = %s AND `PathId` = %s";

        if($this->database->update($stmt, [$hash, $this->getPathId($request)]) != 1) {
            $content['error'] = true;
            $content['text'] = 'Entweder wurde der Eintrag bereits freigeschaltet oder gelöscht!';
        } else {
            $content['error'] = false;
            $content['text'] = 'Der Gästebucheintrag wurde erfolgreich freigeschaltet und ist nun für alle sichtbar.';
        }
        $content['url_back'] = $this->getPath($request);

        return $responseEngine->render($request, $content, "SFW2\\Guestbook\\UnlockEntry");
    }

    /**
     * @throws HttpUnprocessableContent
     * @throws HttpNotFound
     * @throws Exception
     * @noinspection PhpUnused
     */
    public function deleteEntryByHash(Request $request, ResponseEngine $responseEngine): Response
    {
        $hash = filter_input(INPUT_GET, 'hash', FILTER_VALIDATE_REGEXP, ["options" => ["regexp" => "/^[a-f0-9]{32}$/"]]);

        if($hash === false || is_null($hash)) {
            throw new HttpUnprocessableContent("invalid hash given");
        }

        $content = [
            'confirm' => false,
            'url_back' => $this->getPath($request)
        ];

        $stmt = "SELECT * FROM `{TABLE_PREFIX}_guestbook` WHERE `UnlockHash` = %s AND PathId = %s";
        $entry = $this->database->selectRow($stmt, [$hash, $this->getPathId($request)]);
        if(empty($entry)) {
            $content['error'] = true;
            $content['text'] = 'Der Eintrag wurde bereits gelöscht!';

            return $responseEngine->render($request, $content, "SFW2\\Guestbook\\UnlockEntry");
        }

        $confirmed = filter_input(INPUT_GET, 'confirmed', FILTER_VALIDATE_BOOLEAN);
        if(!$confirmed) {
            $content['url_delete'   ] = "?do=deleteEntryByHash&hash=$hash&confirmed=1";
            $content['confirm'      ]   = true;
            $content['creation_date'] = $this->dateTimeHelper->getDate(DateTimeHelper::FULL_DATE, $entry['CreationDate']);
            $content['message'      ] = $this->getFormatedMessage($entry['Message']);
            $content['author'       ] = $this->getAuthor($entry['Name'], $entry['Location']);

            return $responseEngine->render($request, $content, "SFW2\\Guestbook\\UnlockEntry");
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_guestbook` WHERE `UnlockHash` = %s";
        if(!$this->database->delete($stmt, [$hash])) {
            throw new HttpUnprocessableContent("invalid hash given");
        }
        $content['error'] = false;
        $content['text'] = 'Der Gästebucheintrag wurde erfolgreich gelöscht.';
        $content['confirm'] = false;

        return $responseEngine->render($request, $content, "SFW2\\Guestbook\\UnlockEntry");
    }

    /**
     * @throws HttpUnprocessableContent
     * @throws HttpNotFound
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function delete(Request $request, ResponseEngine $responseEngine): Response
    {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new HttpUnprocessableContent("invalid hash given");
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_guestbook` WHERE `Id` = %s AND `PathId` = %s";

        if(!$this->database->delete($stmt, [$entryId, $this->getPathId($request)])) {
            throw new HttpUnprocessableContent("no entry found");
        }
         return $responseEngine->render(
            request: $request,
            template: "SFW2\\Guestbook\\UnlockEntry"
        );
    }

    /**
     * @throws HttpNotFound
     * @throws Exception
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function create(Request $request, ResponseEngine $responseEngine): Response
    {
        $rulset = new Ruleset();
        $rulset->addNewRules('name', new IsNotEmpty());
        $rulset->addNewRules('location', new IsAvailable());
        $rulset->addNewRules('message', new IsNotEmpty());
        $rulset->addNewRules('email', new IsEMailAddress());
        $rulset->addNewRules('terms', new IsTrue());

        $values = [];

        $validator = new Validator($rulset);

        $error = $validator->validate($_POST, $values);

        if(!$error) {
            return
                $responseEngine->
                render($request, ['sfw2_payload' => $values])->
                withStatus(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
        }

        $unlockHash = md5(openssl_random_pseudo_bytes(64));

        $data = [
            'date' => date("m.d.Y"),
            'time' => date("H:i"),
            'name' => $values['name']['value'],
            'email' => $values['email']['value'],
            'location' => $values['location']['value'] ?: '<unbekannt>',
            'message' => $values['message']['value'],
            'url_unlock' => $this->getUrl($request) . "?do=unlockEntryByHash&hash=$unlockHash",
            'url_delete' => $this->getUrl($request) . "?do=deleteEntryByHash&hash=$unlockHash"
        ];

        $this->mailer->send(
            $request->getAttribute('sfw2_project')['webmaster_mail_address'],
            "Neuer Gästebucheintrag von '{{name}}'",
            "SFW2\\Guestbook\\GuestbookMailTemplate",
            $data
        );

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_guestbook` " .
            "SET `CreationDate` = NOW(), " .
            "`Message` = %s, " .
            "`Name` = %s, " .
            "`Location` = %s, " .
            "`EMail` = %s, " .
            "`PathId` = %s, " .
            "`UnlockHash` = %s, " .
            "`Visible` = '0' ";

        $this->database->insert(
            $stmt,
            [
                $values['message']['value'],
                $values['name']['value'],
                $values['location']['value'],
                $values['email']['value'],
                $this->getPathId($request),
                $unlockHash
            ]
        );

        return $responseEngine->render($request, [
            'title' => 'Gästebucheintrag',
            'description' => 'Eintrag wurde erfolgreich angelegt. Bitte habe ein wenig Geduld bis dein Eintrag freigeschaltet wird',
            'reload' => false
        ]);
    }

    protected function getFormatedMessage(string $text, bool $truncated = false) : string
    {
        $maxLength = 200;

        if(strlen($text) > $maxLength && $truncated) {
            $lastPos = ($maxLength - 3) - strlen($text);
            $text = substr($text, 0, strrpos($text, ' ', $lastPos)) . '...';
        }

        // FIXME remove this when databaseentries were formatted
        $text = str_replace('\\r\\n', PHP_EOL, $text);
        $text = str_replace('\\"', '"', $text);

        return nl2br(htmlspecialchars($text));
    }

    protected function getAuthor(string $name, string $location) : string
    {
        $author = htmlspecialchars(trim($name));
        $location = htmlspecialchars(trim($location));

        if($location != '') {
            $author .= ' aus ' . $location;
        }
        return $author;
    }

    /**
     * @throws Exception
     */
    protected function getEntries(bool $truncateMessage, int $pathId): array
    {

        $stmt = /** @lang MySQL */
            "SELECT `Id`, `CreationDate`, `Message`, `Name`, `Location`, `Email` " .
            "FROM `{TABLE_PREFIX}_guestbook` AS `guestbook` " .
            "WHERE `PathId` = %s AND `Visible` = '1' " .
            "ORDER BY `guestbook`.`CreationDate` DESC ";

        $rows = $this->database->select($stmt, [$pathId]);
        $max = strlen((string)count($rows));
        $max = max($max, 3);
        $i = 0;
        $entries = [];
        foreach($rows as $row) {
            $cd = $this->dateTimeHelper->getDate(DateTimeHelper::FULL_DATE, $row['CreationDate']);

            $entry = [];
            $entry['id'      ] = $row['Id'];
            $entry['nb'      ] = str_pad(++$i, $max, '0', STR_PAD_LEFT);
            $entry['date'    ] = $cd;
            $entry['message' ] = $this->getFormatedMessage($row['Message'], $truncateMessage);
            $entry['author'  ] = $this->getAuthor($row['Name'], $row['Location']);
            $entries[] = $entry;
        }
        return $entries;
    }
}

