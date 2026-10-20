<?php

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Orders\Services\OrderService;
use Modules\Payments\DTOs\PaymentFilters;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Http\Requests\ListPaymentsRequest;
use Modules\Payments\Http\Requests\ProcessPaymentRequest;
use Modules\Payments\Http\Resources\PaymentResource;
use Modules\Payments\Services\PaymentService;

/**
 * Payments as a sub-resource of an order: /orders/{order}/payments.
 */
class OrderPaymentController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
    ) {}

    public function index(ListPaymentsRequest $request, int $order): AnonymousResourceCollection
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $model = $this->orders->findForUser($order, $userId, withItems: false);

        $filters = $request->filters();

        return PaymentResource::collection($this->payments->paginate(
            $userId,
            new PaymentFilters($filters->status, $filters->method, $model->id),
            $this->perPage($request->perPage()),
        ));
    }

    /**
     * 201 for a payment that went through or is awaiting settlement, 402 for
     * a decline — the body is the payment either way, so the client always
     * has its id. A replayed Idempotency-Key answers 200 with the original
     * payment and charges nothing.
     */
    public function store(ProcessPaymentRequest $request, int $order): JsonResponse
    {
        $model = $this->orders->findForUser($order, (int) $request->user()->getAuthIdentifier(), withItems: false);

        $processed = $this->payments->process($model, $request->toData());
        $payment = $processed->payment;

        $status = match (true) {
            $processed->replayed => Response::HTTP_OK,
            $payment->status === PaymentStatus::Failed => Response::HTTP_PAYMENT_REQUIRED,
            default => Response::HTTP_CREATED,
        };

        $response = (new PaymentResource($payment))->response()->setStatusCode($status);

        // Location only on 201. PHP rewrites any other status that carries a
        // Location header into a 302, turning a replay or a decline into a
        // redirect.
        if ($status === Response::HTTP_CREATED) {
            $response->header('Location', route('api.v1.payments.show', $payment));
        }

        return $processed->replayed ? $response->header('Idempotent-Replayed', 'true') : $response;
    }
}
