<?php
namespace BetterLinks\Interfaces;
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface ImportOneClickInterface {

	public function run_importer( $data );
}
