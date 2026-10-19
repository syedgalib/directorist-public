<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** A safe, actionable failure. Internal exception messages never cross MCP. */
class Failure extends \RuntimeException {
    public $reason;

    public $details;

    public function __construct( $reason, $message, array $details = [] ) {
        parent::__construct( $message );
        $this->reason  = $reason;
        $this->details = $details;
    }

    public function as_error() {
        return new \WP_Error(
            'directorist_mcp_' . $this->reason, $this->getMessage(), array_merge(
                [
                    'code'      => $this->reason,
                    'retryable' => false,
                    'committed' => false,
                ], $this->details
            )
        );
    }
}
