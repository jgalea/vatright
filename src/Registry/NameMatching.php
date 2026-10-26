<?php

namespace Vatright\Registry;

use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;

/**
 * A registry that can compare a company name with the one registered for a
 * number. A mismatch the registry judged comes back as a VALID result with the
 * detail 'name_mismatch', one found by comparing the typed name with the name
 * the registry returned with 'name_mismatch_local'; no verdict, whatever the
 * reason, comes back as a plain result.
 */
interface NameMatching extends Registry {

	const MISMATCH       = 'name_mismatch';
	const MISMATCH_LOCAL = 'name_mismatch_local';

	public function check_named( VatNumber $n, ?VatNumber $requester, string $trader_name ): ValidationResult;
}
