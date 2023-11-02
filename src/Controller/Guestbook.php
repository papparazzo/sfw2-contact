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

use _PHPStan_95cdbe577\Nette\Neon\Exception;
use DateTime;
use DateTimeZone;
use IntlDateFormatter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SFW2\Core\HttpExceptions\HttpUnprocessableContent;
use SFW2\Database\DatabaseInterface;
use SFW2\Routing\AbstractController;

use SFW2\Routing\ResponseEngine;
use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;
use SFW2\Validator\Validators\IsAvailable;
use SFW2\Validator\Validators\IsEMailAddress;
use SFW2\Validator\Validators\IsTrue;


class Guestbook extends AbstractController {

   # use DateTimeHelperTrait;
  #  use EMailHelperTrait;

    protected string $title;
    protected string $description;

    public function __construct(
        protected DatabaseInterface $database
    ) {
        $this->title = 'Gästebuch';
        $this->description = 'Hier ist unser Gästebuch. Wenn Du magst dann lass einen Eintrag zurück.';
    }

    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        #$pathId = (int)$request->getAttribute('sfw2_project')['webmaster_mail_address'];
        #$path = (int)$request->getAttribute('sfw2_project')['path'];

        $content = [
            'title' => $this->title,
            'description' => $this->description,
            'entries' => $this->getEntries(false, $this->getPathId($request))
        ];

        return $responseEngine->render(
            $request,
            "SFW2\\Guestbook\\Guestbook",
            $content
        );
    }

    /**
     * @throws HttpUnprocessableContent
     */
    public function unlockEntryByHash(Request $request, ResponseEngine $responseEngine): Response {
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

        return $responseEngine->render(
            $request,
            "SFW2\\Guestbook\\UnlockEntry",
            $content
        );
    }

    /**
     * @throws HttpUnprocessableContent
     */
    public function deleteEntryByHash(Request $request, ResponseEngine $responseEngine): Response {
        $hash = filter_input(INPUT_GET, 'hash', FILTER_VALIDATE_REGEXP, ["options" => ["regexp" => "/^[a-f0-9]{32}$/"]]);

        if($hash === false || is_null($hash)) {
            throw new HttpUnprocessableContent("invalid hash given");
        }

        $content = [
            'confirm' => false
        ];

        $stmt = "SELECT * FROM `{TABLE_PREFIX}_guestbook` WHERE `UnlockHash` = %s AND PathId = %s";
        $entry = $this->database->selectRow($stmt, [$hash, $this->getPathId($request)]);
        if(empty($entry)) {
            $content['error'] = true;
            $content['text'] = 'Der Eintrag wurde bereits gelöscht!';

            return $responseEngine->render(
                $request,
                "SFW2\\Guestbook\\UnlockEntry",
                $content
            );
        }

        $confirmed = filter_input(INPUT_GET, 'confirmed', FILTER_VALIDATE_BOOLEAN);
        if(!$confirmed) {
            $entry['CreationDate'] = $this->getShortDate($entry['CreationDate']);
            $entry['Message'     ] = $this->getFormatedMessage($entry['Message']);
            $entry['Author'      ] = $this->getAuthor($entry['Name'], $entry['Location'], $entry["Email"]);
            $urlDelete =
                $_SERVER['REQUEST_SCHEME'] . '://' .
                $_SERVER['HTTP_HOST'] . $this->path .
                '?do=deleteEntryByHash&hash=' . $hash . '&confirmed=1';

            $content['urlDelete'] = $urlDelete;
            $content['entry'] = $entry;
            $content['confirm'] = true;
            return $responseEngine->render(
                $request,
                "SFW2\\Guestbook\\UnlockEntry",
                $content
            );
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_guestbook` WHERE `UnlockHash` = %s";
        if(!$this->database->delete($stmt, [$hash])) {
            throw new HttpUnprocessableContent("invalid hash given");
        }
        $content['error'] = false;
        $content['text'] = 'Der Gästebucheintrag wurde erfolgreich gelöscht.';
        $content['confirm'] = false;

        return $responseEngine->render(
            $request,
            "SFW2\\Guestbook\\UnlockEntry",
            $content
        );
    }

    /**
     * @throws HttpUnprocessableContent
     */
    public function delete(Request $request, ResponseEngine $responseEngine): Response {

        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new HttpUnprocessableContent("invalid hash given");
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_guestbook` WHERE `Id` = %s AND `PathId` = %s";

        if(!$this->database->delete($stmt, [$entryId, $this->getPathId($request)])) {
            throw new HttpUnprocessableContent("no entry found");
        }
         return $responseEngine->render(
            $request,
            "SFW2\\Guestbook\\UnlockEntry"
        );
    }

    /**
     * @throws Exception
     */
    public function create(Request $request, ResponseEngine $responseEngine): Response {

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
            #$content->setError(true);
            return $responseEngine->render(
                $request,
                "SFW2\\Guestbook\\UnlockEntry",
                $values
            );
        }

        $unlockHash = md5(openssl_random_pseudo_bytes(64));

        $this->sendRequestMail(
            $values['message']['value'],
            $values['name']['value'],
            $values['location']['value'],
            $values['email']['value'],
            $unlockHash
        );

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_guestbook` " .
            "SET `CreationDate` = NOW(), " .
            "`Message` = '%s', " .
            "`Name` = '%s', " .
            "`Location` = '%s', " .
            "`EMail` = '%s', " .
            "`PathId` = '%d', " .
            "`UnlockHash` = '%s', " .
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

        return $responseEngine->render(
            $request,
            "SFW2\\Guestbook\\UnlockEntry",
        );
    }

    protected function getFormatedMessage(string $text, bool $truncated = false) : string {
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

    protected function getAuthor(string $name, string $location, string $email) : string {
        $author = htmlspecialchars(trim($name));
        $email = trim($email);
        $location = htmlspecialchars(trim($location));

       # if($email != '') {
       #     $author = $this->getEMail($email, $author, '');
       # }

        if($location != '') {
            $author .= ' aus ' . $location;
        }
        return $author;
    }

    /**
     * @throws Exception
     */
    protected function sendRequestMail(string $message, string $name, string $location, string $email, string $unlockHash) : void {

        $emailTo = 'vorstand@springer-singgemeinschaft.de'; // FIXME

        $urlDelete =
            $_SERVER['REQUEST_SCHEME'] . '://' .
            $_SERVER['HTTP_HOST'] . $this->path .
            '?do=deleteEntryByHash&hash=' . $unlockHash;

        $urlUnlock =
            $_SERVER['REQUEST_SCHEME'] . '://' .
            $_SERVER['HTTP_HOST'] . $this->path .
            '?do=unlockEntryByHash&hash=' . $unlockHash;

        $text =
            "Es gibt einen neuen Gästebucheintrag." . PHP_EOL .
            "Am " . date("m.d.Y") . " um " . date("H:i") . " " .
            "Uhr schrieb '" . htmlspecialchars($name) . "' " .
            "aus '" . htmlspecialchars($location) . "' folgende Nachricht: " . PHP_EOL . PHP_EOL .
            htmlspecialchars($message) . PHP_EOL . PHP_EOL .
            "<hr>" .
            "soll diese Nachricht freigeschaltet werden? " . PHP_EOL . PHP_EOL .
            '<a href="' . $urlUnlock . '">ja, bitte freischalten</a>' . PHP_EOL . PHP_EOL .
            '<a href="' . $urlDelete . '">nein, bitte unverzüglich löschen</a>' . PHP_EOL . PHP_EOL;

        $text = nl2br($text);

        $subject = "Neuer Gästebucheintrag von '" . htmlspecialchars($name) . "'";

        $headers = [
            "MIME-Version" => "1.0",
            "Content-type" => "text/html; charset=utf-8",
            "From" => "noreply@springer-singgemeinschaft.de",
            "Bcc" => "stefan.paproth@springer-singgemeinschaft.de" // FIXME
        ];

        if(!mail($emailTo, $subject, $text, $headers)) {
            throw new Exception("Could not send body <$text> to <$emailTo>");
        }
    }

    /**
     * @throws \Exception
     */
    protected function getEntries(bool $truncateMessage, int $pathId): array {

        $stmt =
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
            $cd = $this->getShortDate($row['CreationDate']);

            $entry = [];
            $entry['id'      ] = $row['Id'];
            $entry['nb'      ] = str_pad(++$i, $max, '0', STR_PAD_LEFT);
            $entry['date'    ] = $cd;
            $entry['message' ] = $this->getFormatedMessage($row['Message'], $truncateMessage);
            $entry['author'  ] = $this->getAuthor($row['Name'], $row['Location'], $row["Email"]);
            $entries[] = $entry;
        }
        return $entries;
    }

    // TODO: Make this a trait
    /**
     * @throws \Exception
     * @deprecated
     */
    protected function getShortDate($date = 'now', string $dateTimeZone = 'Europe/Berlin'): bool|string
    {
        if($date === null) {
            return '';
        }

         $local_date = IntlDateFormatter::create(
                'de',
                IntlDateFormatter::LONG,
                IntlDateFormatter::NONE,
                $dateTimeZone,
                null,
                null
            );

        return $local_date->format(new DateTime($date, new DateTimeZone($dateTimeZone)));
    }
}

