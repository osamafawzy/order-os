<?php

namespace Modules\Orders\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Orders\Http\Requests\ListOrdersRequest;
use Modules\Orders\Http\Requests\StoreOrderRequest;
use Modules\Orders\Http\Requests\UpdateOrderRequest;
use Modules\Orders\Http\Resources\OrderResource;
use Modules\Orders\Services\OrderService;

class OrderController extends ApiController
{
    public function __construct(
        private readonly OrderService $orders,
    ) {}

    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        return OrderResource::collection($this->orders->paginate(
            (int) $request->user()->getAuthIdentifier(),
            $request->status(),
            $this->perPage($request->perPage()),
        ));
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create((int) $request->user()->getAuthIdentifier(), $request->toData());

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', route('api.v1.orders.show', $order));
    }

    public function show(Request $request, int $order): OrderResource
    {
        return new OrderResource($this->orders->findForUser($order, (int) $request->user()->getAuthIdentifier()));
    }

    public function update(UpdateOrderRequest $request, int $order): OrderResource
    {
        $model = $this->orders->findForUser($order, (int) $request->user()->getAuthIdentifier());

        return new OrderResource($this->orders->update($model, $request->toData()));
    }

    public function destroy(Request $request, int $order): Response
    {
        $this->orders->delete($this->orders->findForUser($order, (int) $request->user()->getAuthIdentifier()));

        return response()->noContent();
    }
}
