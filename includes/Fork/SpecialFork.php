<?php

namespace WikiMirror\Fork;

use SpecialPage;

class SpecialFork extends SpecialPage {
	public function __construct() {
		parent::__construct( 'Fork', 'fork', false );
	}
}
