<?php

/**
 *  SFW2 - SimpleFrameWork
 *
 *  Copyright (C) 2023  Stefan Paproth
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

declare(strict_types=1);

namespace SFW2\Guestbook\Controller;

use Exception;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SFW2\Core\Utils\Mailer;
use SFW2\Routing\AbstractController;
use SFW2\Routing\ResponseEngine;
use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsEMailAddress;
use SFW2\Validator\Validators\IsNotEmpty;
use SFW2\Validator\Validators\IsTrue;

class ContactController extends AbstractController
{
    public function __construct(private readonly Mailer $mailer)
    {
    }

    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        // TODO: get data from database
        $content = [
            'title' => 'Tritt mit uns in Kontakt',
            'location' => '',
            'description' => '',

            'phone' => '',
            'mail' => ''
        ];

        return $responseEngine->render($request, $content, "SFW2\\Guestbook\\Contact");
    }

    /**
     * @throws Exception
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function create(Request $request, ResponseEngine $responseEngine): Response
    {
        $rulset = new Ruleset();
        $rulset->addNewRules('name', new IsNotEmpty());
        $rulset->addNewRules('email', new IsNotEmpty(), new IsEMailAddress());
        $rulset->addNewRules('message', new IsNotEmpty());
        $rulset->addNewRules('terms', new IsTrue());

        $validator = new Validator($rulset);
        $values = [];

        $error = $validator->validate($_POST, $values);

        if (!$error) {
            return
                $responseEngine->
                render($request, ['sfw2_payload' => $values])->
                withStatus(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
        }

        $data = [
            'name' => $values['name']['value'],
            'message' => $values['message']['value'],
            'email' => $values['email']['value'],
        ];

        $this->mailer->send(
            $request->getAttribute('sfw2_project')['webmaster_mail_address'],
            "Neue Nachricht vom Kontaktformular von '{{name}}'",
            "SFW2\\Guestbook\\ContactMailTemplate",
            $data
        );

        return $responseEngine->render($request, [
            'title' => 'Kontaktanfrage',
            'description' => 'Nachricht wurde erfolgreich verschickt. Bitte habe ein wenig Geduld, Antwort ist unterwegs',
            'reload' => true // FIXME Relaod ist hier eigentlich überflüssig... Aber das Formular ist noch gefüllt...
        ]);
    }
}