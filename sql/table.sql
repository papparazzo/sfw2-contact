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

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}_guestbook` (
    `Id` INT(10) UNSIGNED NOT NULL,
    `PathId` INT(10) UNSIGNED NOT NULL,
    `CreationDate` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `Message` text CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
    `Name` VARCHAR(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
    `Email` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
    `Location` VARCHAR(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
    `UnlockHash` VARCHAR(64) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
    `Visible` TINYINT(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `{TABLE_PREFIX}_guestbook` ADD PRIMARY KEY (`Id`);
ALTER TABLE `{TABLE_PREFIX}_guestbook` MODIFY `Id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT;


INSERT INTO `{TABLE_PREFIX}_controller_template` (`ClassName`, `DisplayName`, `Description`, `Data`) VALUES
('SFW2\\Module\\SFW2\\Guestbook\\Controller\\Guestbook', 'Gästebuch', '', '');


COMMIT;

