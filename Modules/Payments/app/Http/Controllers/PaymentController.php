<?php

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Payments\Http\Requests\ListPaymentsRequest;
use Modules\Payments\Http\Resources\PaymentResource;
use Modules\Payments\Services\PaymentService;

class PaymentController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function index(ListPaymentsRequest $request): AnonymousResourceCollection
    {
        return PaymentResource::collection($this->payments->paginate(
            (int) $request->user()->getAuthIdentifier(),
            $request->filters(),
            $this->perPage($request->perPage()),
        ));
    }

    public function show(Request $request, int $payment): PaymentResource
    {
        return new PaymentResource($this->payments->findForUser($payment, (int) $request->user()->getAuthIdentifier()));
    }
}
