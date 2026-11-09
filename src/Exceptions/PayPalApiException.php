<?php

/**
 * PayPal API exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A failed PayPal API call.
 *
 * Carries what PayPal reported (the HTTP status, the error `name`, each
 * `details[].issue`, and the `debug_id` support asks for) and never the
 * request or response body. A status of `0` means no response arrived
 * (a timeout or a connection error).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalApiException extends RuntimeException
{
    /**
     * Creates the exception.
     *
     * @since 1.0.0
     *
     * @param  string                                                         $message    PayPal's message (or a description of the failure).
     * @param  int                                                            $status     HTTP status, or `0` when no response arrived.
     * @param  string|null                                                    $name       PayPal error name (`UNPROCESSABLE_ENTITY`, `invalid_client`, …).
     * @param  array<int, array{issue?: string, description?: string, field?: string}>  $details    PayPal's `details[]`.
     * @param  string|null                                                    $debugId    PayPal's debug id.
     * @param  Throwable|null                                                 $previous   Underlying exception.
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $name = null,
        public readonly array $details = [],
        public readonly ?string $debugId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct( $message, $status, $previous );
    }

    /**
     * Builds the exception from a PayPal error response.
     *
     * Handles both shapes PayPal uses: the REST error object (`name`,
     * `message`, `details`, `debug_id`) and the OAuth one (`error`,
     * `error_description`).
     *
     * @since 1.0.0
     *
     * @param  int                   $status   HTTP status.
     * @param  array<string, mixed>  $body     Decoded response body.
     * @param  string|null           $debugId  The `PayPal-Debug-Id` header.
     *
     * @return self
     */
    public static function fromResponse( int $status, array $body, ?string $debugId = null ): self
    {
        $name    = $body['name'] ?? $body['error'] ?? null;
        $message = $body['message'] ?? $body['error_description'] ?? null;
        $details = [];

        foreach ( (array) ( $body['details'] ?? [] ) as $detail ) {
            if ( ! is_array( $detail ) ) {
                continue;
            }

            $details[] = array_filter( [
                'issue'       => isset( $detail['issue'] ) ? (string) $detail['issue'] : null,
                'description' => isset( $detail['description'] ) ? (string) $detail['description'] : null,
                'field'       => isset( $detail['field'] ) ? (string) $detail['field'] : null,
            ], static fn ( ?string $value ): bool => null !== $value );
        }

        return new self(
            is_string( $message ) && '' !== $message ? $message : sprintf( 'PayPal returned HTTP %d.', $status ),
            $status,
            is_string( $name ) ? $name : null,
            $details,
            $debugId ?? ( isset( $body['debug_id'] ) ? (string) $body['debug_id'] : null ),
        );
    }

    /**
     * Builds the exception for a request that got no response.
     *
     * @since 1.0.0
     *
     * @param  Throwable  $previous  The connection error.
     *
     * @return self
     */
    public static function connectionFailed( Throwable $previous ): self
    {
        return new self( 'PayPal could not be reached: ' . $previous->getMessage(), 0, 'CONNECTION_ERROR', [], null, $previous );
    }

    /**
     * Whether the same request may be sent again: no response arrived, PayPal
     * rate-limited it, or PayPal failed on its side.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isRetryable(): bool
    {
        return 0 === $this->status || 429 === $this->status || $this->status >= 500;
    }

    /**
     * The first `details[].issue` (`INSTRUMENT_DECLINED`, …), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function issue(): ?string
    {
        foreach ( $this->details as $detail ) {
            if ( isset( $detail['issue'] ) && '' !== $detail['issue'] ) {
                return $detail['issue'];
            }
        }

        return null;
    }

    /**
     * Whether any `details[].issue` equals `$issue`.
     *
     * @since 1.0.0
     *
     * @param  string  $issue  Issue code.
     *
     * @return bool
     */
    public function hasIssue( string $issue ): bool
    {
        foreach ( $this->details as $detail ) {
            if ( ( $detail['issue'] ?? null ) === $issue ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether PayPal refused the credentials.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isAuthenticationFailure(): bool
    {
        return 401 === $this->status || 'invalid_client' === $this->name;
    }

    /**
     * Safe context for a log line.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'status'   => $this->status,
            'name'     => $this->name,
            'issue'    => $this->issue(),
            'debug_id' => $this->debugId,
        ];
    }
}
