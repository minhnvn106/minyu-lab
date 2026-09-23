<?php
namespace BetterLinks\API;
if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Controller {

	protected $namespace = BETTERLINKS_PLUGIN_SLUG . '/v1';
	abstract protected function get_items( $request );
	abstract protected function create_item( $request );
	abstract protected function update_item( $request );
	abstract protected function delete_item( $request );
	abstract protected function permissions_check( $request );
}
