<?php

declare(strict_types=1);

namespace Plugins\LoanField\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\LoanField\API\Contracts\FieldServiceContract;

/** Translation only: request in, service call, response out. */
final class ContactController
{
    public function __construct(
        private readonly FieldServiceContract $field,
    ) {
    }

    public function notContacted(Request $request): Response
    {
        return Response::json($this->field->customersNotContacted($request->queryAll()));
    }
}
