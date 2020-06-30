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

namespace SFW2\Module\SFW2\Guestbook\Controller;

use SFW2\Routing\Result\Content;
use SFW2\Routing\AbstractController;
use SFW2\Routing\Resolver\ResolverException;
use SFW2\Routing\PathMap\PathMap;

use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;

use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;
use SFW2\Validator\Validators\IsAvailable;
use SFW2\Validator\Validators\IsEMailAddress;
use SFW2\Validator\Validators\IsTrue;

use SFW2\Core\Database;

class Guestbook extends AbstractController {

    use DateTimeHelperTrait;
    use EMailHelperTrait;

    protected Database $database;
    protected string $title;
    protected string $description;
    protected string $path;

    public function __construct(int $pathId, Database $database, PathMap $path, string $title = '') {
        parent::__construct($pathId);
        $this->database = $database;
        $this->path  = $path->getPath($pathId);

        $this->title = 'Gästebuch';
        $this->description = 'Hier ist unser Gästebuch.';
    }

    public function index(bool $all = false): Content {
        unset($all);
        $content = new Content('SFW2\\Guestbook\\guestbook');


        $cnt = $this->database->selectCount('{TABLE_PREFIX}_guestbook', "WHERE `PathId` = '%s' AND `Visible` = '1'", [$this->pathId]);
        $content->assign('count', $cnt);
        $content->assign('entries', $this->getEntries(true));
        $content->assign('title', $this->title);
        $content->assign('description', $this->description);
        return $content;
    }

    public function unlockEntryByHash() : Content {
        $hash = filter_input(INPUT_GET, 'hash', FILTER_VALIDATE_REGEXP, ["options" => ["regexp" => "/^[a-f0-9]{32}$/"]]);
        if($hash === false || is_null($hash)) {
            throw new ResolverException("invalid hash given", ResolverException::INVALID_DATA_GIVEN);
        }

        $content = new Content('SFW2\\Guestbook\\unlockEntry');
        $content->assign('confirm', false);
        $stmt = "UPDATE `{TABLE_PREFIX}_guestbook` SET `Visible` = '1' WHERE `UnlockHash` = '%s'";
        if($this->database->update($stmt, [$hash]) != 1) {
            $content->assign('error', true);
            $content->assign('text', 'Entweder wurde der Eintrag bereits freigeschaltet oder gelöscht!');
        } else {
            $content->assign('error', false);
            $content->assign('text', 'Der Gästebucheintrag wurde erfolgreich freigeschaltet und ist nun für alle sichtbar.');
        }
        return $content;
    }

    public function deleteEntryByHash() : Content {
        $hash = filter_input(INPUT_GET, 'hash', FILTER_VALIDATE_REGEXP, ["options" => ["regexp" => "/^[a-f0-9]{32}$/"]]);

        if($hash === false || is_null($hash)) {
            throw new ResolverException("invalid hash given", ResolverException::INVALID_DATA_GIVEN);
        }
        $content = new Content('SFW2\\Guestbook\\unlockEntry');
        $content->assign('confirm', false);

        $stmt = "SELECT * FROM `{TABLE_PREFIX}_guestbook` WHERE `UnlockHash` = '%s'";
        $entry = $this->database->selectRow($stmt, [$hash]);
        if(empty($entry)) {
            $content->assign('error', true);
            $content->assign('text', 'Der Eintrag wurde bereits gelöscht!');
            return $content;
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

            $content->assign('urlDelete', $urlDelete);
            $content->assign('entry', $entry);
            $content->assign('confirm', true);
            return $content;
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_guestbook` WHERE `UnlockHash` = '%s'";
        if(!$this->database->delete($stmt, [$hash])) {
            throw new ResolverException("invalid hash given", ResolverException::INVALID_DATA_GIVEN);
        }
        $content->assign('error', false);
        $content->assign('text', 'Der Gästebucheintrag wurde erfolgreich gelöscht.');
        $content->assign('confirm', false);
        return $content;
    }

    public function delete(bool $all = false) : Content {
        unset($all);
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_guestbook` WHERE `Id` = '%s' AND `PathId` = '%s'";

        if(!$this->database->delete($stmt, [$entryId, $this->pathId])) {
            throw new ResolverException("no entry found", ResolverException::NO_PERMISSION);
        }
        return new Content();
    }

    public function create() : Content {
        $content = new Content();

        $rulset = new Ruleset();
        $rulset->addNewRules('name', new IsNotEmpty());
        $rulset->addNewRules('location', new IsAvailable());
        $rulset->addNewRules('message', new IsNotEmpty());
        $rulset->addNewRules('email', new IsEMailAddress());
        $rulset->addNewRules('terms', new IsTrue());

        $values = [];

        $validator = new Validator($rulset);
        $error = $validator->validate($_POST, $values);
        $content->assignArray($values);

        if(!$error) {
            $content->setError(true);
            return $content;
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
                $this->pathId,
                $unlockHash
            ]
        );

        return $content;
    }

    public function showAll() : Content {
        $entryId = (int)filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        $content = new Content('SFW2\\Guestbook\\showAll');
        $content->assign('title', 'Gäste&shy;buch&shy;einträge');
        $content->assign('entries', $this->getEntries(false));
        $content->assign('highlightId', $entryId);
        return $content;
    }

    protected function getFormatedMessage(string $text, bool $truncated = false) : string {
        $maxLength = 200;

        if(strlen($text) > $maxLength && $truncated) {
            $lastPos = ($maxLength - 3) - strlen($text);
            $text = substr($text, 0, strrpos($text, ' ', $lastPos)) . '...';
        }

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

        if(mail($emailTo, $subject, $text, $headers) == false) {
            throw new Exception("Could not send body <$text> to <$emailTo>");
        }
    }

    protected function getEntries(bool $truncateMessage) : array {
        $stmt =
            "SELECT `Id`, `CreationDate`, `Message`, `Name`, `Location`, `Email` " .
            "FROM `{TABLE_PREFIX}_guestbook` AS `guestbook` " .
            "WHERE `PathId` = '%s' AND `Visible` = '1' " .
            "ORDER BY `guestbook`.`CreationDate` DESC ";

        $rows = $this->database->select($stmt, [$this->pathId]);
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
}

