<?php

/**
 *  SFW2 - SimpleFrameWork
 *
 *  Copyright (C) 2017  Stefan Paproth
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

namespace SFW2\Contact\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SFW2\Database\DatabaseException;
use SFW2\Database\DatabaseInterface;
use SFW2\Routing\ResponseEngine;
use SFW2\Routing\Result\Content;
use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Controllers\Widget\Obfuscator\Phone;
use SFW2\Controllers\Widget\Obfuscator\WhatsApp;
use SFW2\Routing\AbstractController;

class ContactList extends AbstractController
{
    public function __construct(
        private readonly DatabaseInterface $database
    ) {
    }

    /**
     * @throws DatabaseException
     */
    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        $stmt = /** @lang MySQL */
            "SELECT `user`.`FirstName`, `user`.`LastName`, " .
            "IF(`user`.`Sex` = 'MALE', 'Herr', 'Frau') AS `Sex`, " .
            "`user`.`Phone2`, `user`.`Email`, `user`.`Phone1`, " .
            "`position`.`Position`, " .
            "IFNULL(`division`.`Alias`, `division`.`Name`) AS `Division` " .
            "FROM `{TABLE_PREFIX}_position` AS `position` " .
            "INNER JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `position`.`UserId` = `user`.`Id` " .
            "LEFT JOIN `{TABLE_PREFIX}_division` AS `division` " .
            "ON `division`.`Id` = `position`.`DivisionId` " .
            "ORDER BY `division`.`Position`, `position`.`Order` ";

        $rows = $this->database->select($stmt);

        $entries = [];
        $lp = '';
        $ld = '';
        foreach($rows as $row) {
            $user = [];
            $user['position' ] = '';
            $user['name'     ] = $this->getName($row);

            if($ld != $row['Division'] || $lp != $row['Position']){
                $user['position' ] = $row['Position'];
            }
            $user['phone'    ] = $this->getPhoneNumber($row['Phone1']);
            $user['emailaddr'] = (string)(new EMail($row["Email"]));

            $entries[$row['Division']][] = $user;

            if($row['Phone2'] != '') {
                $user['name'     ] = '';
                $user['position' ] = '';
                $user['emailaddr'] = null;
                $user['phone'    ] = $this->getPhoneNumber($row['Phone2'] ?? '');
                $entries[$row['Division']][] = $user;
            }

            $lp = $row['Position'];
            $ld = $row['Division'];
        }

        $content = new Content('SFW2\\Controllers\\content/kontakt');
        $content->assign('entries', $entries);
        return $content;
    }

    protected function getPhoneNumber($phone) : string {
        if($phone == '') {
            return '';
        }
        return (string)(new Phone(
            $phone,
            'Tel.: ' . $phone
        ));
    }

    protected function getName(array $entry) : string {
        return
            '<a class="link" ' .
            'tabindex="0" ' .
            'role="button" ' .
            'data-toggle="popover" ' .
            'data-trigger="focus" ' .
            'title="Was möchtest Du machen?" ' .
            'data-content="' .
            '">' . $entry['Sex'] . ' ' . $entry['FirstName'] . ' ' . $entry['LastName'] . '</a>' .
                $this->getPopperContent($entry);
    }

    protected function getPopperContent(array $entry) : string {
        $rev = "<div class='noshow'>" .
                "<div>" . (new EMail($entry["Email"], 'E-Mail: '. $entry["Email"])) . "</div>";
        if($entry['Phone1'] != '') {
            $rev .= "<div>" . (new Phone($entry['Phone1'], 'Tel.: ' . $entry['Phone1'])) . "</div>";
        }
        if($this->isMobile($entry['Phone1'])) {
            $rev .= "<div>" . (new WhatsApp($entry['Phone1'], 'WhatsApp: ' . $entry['Phone1'])) . "</div>";
        }
        if($entry['Phone2'] != '') {
            $rev .= "<div>" . (new Phone($entry['Phone2'], 'Tel.: ' . $entry['Phone2'])) . "</div>";
        }
        if($this->isMobile($entry['Phone2'])) {
            $rev .= "<div>" . (new WhatsApp($entry['Phone2'], 'WhatsApp: ' . $entry['Phone2'])) . "</div>";
        }
        return $rev . "</div>";
    }

    protected function isMobile(string $number) : bool {
        $number = preg_replace('#[^0-9]#', '', $number);
        $nb = [
            '0150', '01505', '0151', '01511', '01512', '01514', '01515', '0152', '01520', '01522',
            '01525', '0155', '0157', '01570', '01575', '01577', '01578', '0159', '0160', '0162',
            '0163','0170', '0171', '0172', '0173', '0174', '0175', '0176', '0177', '0178', '0179'
        ];

        foreach($nb as $v) {
            if(str_starts_with($number, $v)) {
                return true;
            }
        }
        return false;
    }
}
