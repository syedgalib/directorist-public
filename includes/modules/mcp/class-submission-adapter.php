<?php
namespace Directorist\MCP;

use Directorist\Fields\Fields;

defined( 'ABSPATH' ) || exit;

/** Reuse native validation without running mutation hooks during preview. */
class Submission_Adapter extends \Directorist\AddListingForm\SubmissionController {
    public static function validate_only( array $data, $directory ) {
        // MCP resolves attachment IDs itself. The API mode moves/deletes temporary
        // files, whereas the ordinary native mode stores the validated URL.
        self::$from                = 'mcp';
        self::$selected_categories = null;
        Support::unwrap( parent::validate_terms_and_conditions( $directory, $data ) );
        parent::cache_selected_categories( $directory, $data );
        $errors = [];
        foreach ( directorist_get_listing_form_fields( $directory ) as $props ) {
            $field = Fields::create( $props );
            if ( $field->is_admin_only() ) {
                continue;
            }
            $result = parent::validate_field( $field, $data );
            if ( ! $result['is_valid'] ) {
                $errors[ $field->get_key() ] = $result['message'];
            }
        }
        if ( $errors ) {
            Support::fail( 'validation_failed', __( 'Some listing fields are invalid.', 'directorist' ), [ 'field_errors' => $errors ] );
        }
    }

    public static function apply( array $data ) {
        self::$selected_categories = null;
        return parent::submit( $data, 'mcp' );
    }
}
