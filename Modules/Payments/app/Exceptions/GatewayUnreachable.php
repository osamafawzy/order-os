<?php

namespace Modules\Payments\Exceptions;

use RuntimeException;

/**
 * Thrown by a gateway when the request provably never reached the vendor —
 * DNS failure, connection refused, the TLS handshake failed, a circuit
 * breaker open — so no money can have moved and retrying is safe.
 *
 * Anything else a gateway throws, a read timeout above all, is treated as an
 * unknown outcome: the vendor may have charged the card and failed to say so.
 * Only throw this when you are certain the charge was not sent.
 */
class GatewayUnreachable extends RuntimeException {}
