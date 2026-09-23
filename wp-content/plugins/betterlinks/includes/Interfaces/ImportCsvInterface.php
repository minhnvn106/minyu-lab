<?php
namespace BetterLinks\Interfaces;
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface ImportCsvInterface {

	public function start_importing( $data, $prefix = '' );
}
