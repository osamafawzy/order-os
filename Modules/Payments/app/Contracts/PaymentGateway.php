<?php

namespace Modules\Payments\Contracts;

use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\Exceptions\GatewayUnreachable;

/**
 * A way of taking money — the strategy in the strategy pattern.
 *
 * Nothing outside a gateway class may know how a particular gateway works.
 * PaymentService talks to this interface only, GatewayRegistry builds the
 * concrete class named in config, and the HTTP layer asks the gateway itself
 * which extra fields it needs. Adding a gateway is therefore one class and
 * one config entry; no service, controller, request or migration changes.
 *
 * See docs/ADDING_A_PAYMENT_GATEWAY.md.
 */
interface PaymentGateway
{
    /**
     * Stable identifier — the `method` a client sends and the value stored on
     * every payment this gateway processes. Changing it orphans history.
     */
    public function key(): string;

    /** Human-readable name for the gateway list endpoint. */
    public function name(): string;

    /**
     * Switched on *and* configured. A gateway enabled in the database but
     * missing its API key in this environment is not available, and the API
     * says so rather than failing at charge time.
     */
    public function isAvailable(): bool;

    /**
     * Validation rules for the gateway-specific `details` object of a payment
     * request, without the `details.` prefix — e.g. ['card_number' => [...]].
     *
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array;

    /**
     * Attempt the charge.
     *
     * Declines are a normal outcome and come back as a failed ChargeResult.
     *
     * Throw GatewayUnreachable only when the request provably never left
     * (connection refused, DNS): the payment is failed and may be retried.
     * Any other exception — a timeout above all — means the outcome is
     * unknown, and the payment is held pending for reconciliation so that a
     * retry cannot charge twice.
     *
     * @throws GatewayUnreachable
     */
    public function charge(ChargeRequest $request): ChargeResult;
}
