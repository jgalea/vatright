<?php

namespace Vatright\Registry;

/**
 * Compares the company name a customer typed with the one a registry returns.
 *
 * Both names are folded to lower case ASCII, punctuation and legal forms
 * (GmbH, S.A., sp. z o.o., "Spółka Akcyjna" and so on) are dropped, and "&"
 * counts as "and". The names match when what is left is equal, or when all the
 * words of one are among the words of the other, so "NV Anheuser-Busch InBev"
 * matches "Anheuser-Busch InBev" and a trade name prefix matches the full
 * name. A refused order is a lost sale, so every doubt is a match: a registry
 * name that is empty, a placeholder or only a legal form, a typed name that
 * is only a legal form, and a pair where only one side is in a script this
 * can't fold (Cyrillic, Greek) all match.
 */
final class CompanyName {

	const LEGAL_FORMS = array(
		'gmbh',
		'gesmbh',
		'ag',
		'kg',
		'ug',
		'ek',
		'ohg',
		'gbr',
		'mbh',
		'se',
		'ltd',
		'limited',
		'plc',
		'llp',
		'llc',
		'inc',
		'incorporated',
		'corp',
		'corporation',
		'sa',
		'sas',
		'sasu',
		'sarl',
		'eurl',
		'sprl',
		'bvba',
		'cvba',
		'vzw',
		'asbl',
		'snc',
		'sci',
		'scs',
		'sca',
		'sl',
		'slu',
		'sau',
		'sgps',
		'spa',
		'srl',
		'bv',
		'nv',
		'cv',
		'vof',
		'ab',
		'hb',
		'kb',
		'oy',
		'oyj',
		'as',
		'asa',
		'aps',
		'ou',
		'sia',
		'uab',
		'sro',
		'doo',
		'dd',
		'jdoo',
		'kft',
		'zrt',
		'nyrt',
		'rt',
		'bt',
		'kkt',
		'eood',
		'ood',
		'ead',
		'ad',
		'lda',
		'ltda',
		'unipessoal',
		'sp',
		'zoo',
		'oo',
	);

	const LEGAL_PHRASES = array(
		'spolka akcyjna',
		'spolka z ograniczona odpowiedzialnoscia',
		'sp z oo',
		'sp zoo',
		'spolka komandytowa',
		'spolka jawna',
		'societa per azioni',
		'societa a responsabilita limitata',
		'societa in accomandita semplice',
		'societa in nome collettivo',
		'sociedad anonima',
		'sociedad limitada',
		'sociedad de responsabilidad limitada',
		'sociedad limitada unipersonal',
		'sociedade anonima',
		'sociedade por quotas',
		'sociedade unipessoal',
		'sociedade unipessoal por quotas',
		'naamloze vennootschap',
		'besloten vennootschap',
		'besloten vennootschap met beperkte aansprakelijkheid',
		'commanditaire vennootschap',
		'vennootschap onder firma',
		'societe anonyme',
		'societe a responsabilite limitee',
		'societe par actions simplifiee',
		'societe en commandite',
		'aktiengesellschaft',
		'gesellschaft mit beschrankter haftung',
		'kommanditgesellschaft',
		'eingetragener kaufmann',
		'eingetragene kauffrau',
		'public limited company',
		'private limited company',
		'limited liability company',
		'limited liability partnership',
		'aktiebolag',
		'osakeyhtio',
		'aktieselskab',
		'anpartsselskab',
		'aksjeselskap',
		'uzdaroji akcine bendrove',
		'akciju sabiedriba',
		'sabiedriba ar ierobezotu atbildibu',
		'spolecnost s rucenim omezenym',
		'akciova spolecnost',
		'druzba z omejeno odgovornostjo',
		'delnicka druzba',
		'korlatolt felelossegu tarsasag',
		'reszvenytarsasag',
		'osauhing',
		'aktsiaselts',
		'drustvo s ogranicenom odgovornoscu',
		'dionicko drustvo',
		'sdruzenie',
		'societate cu raspundere limitata',
		'societate pe actiuni',
	);

	const PLACEHOLDERS = array( 'na', 'n a', 'none', 'null', 'nil', 'unknown', 'not available', 'not provided', 'nicht verfugbar', 'no disponible', 'non disponible', 'x', 'xx', 'xxx' );

	const CONNECTORS = array( 'and', 'und', 'et', 'och', 'og', 'en', 'y', 'e' );

	const EXPAND = array(
		'ä' => 'ae',
		'ö' => 'oe',
		'ü' => 'ue',
		'å' => 'aa',
		'ø' => 'oe',
	);

	const FOLD = array(
		'à' => 'a',
		'á' => 'a',
		'â' => 'a',
		'ã' => 'a',
		'ä' => 'a',
		'å' => 'a',
		'ā' => 'a',
		'ă' => 'a',
		'ą' => 'a',
		'æ' => 'ae',
		'ç' => 'c',
		'ć' => 'c',
		'ĉ' => 'c',
		'č' => 'c',
		'ď' => 'd',
		'đ' => 'd',
		'ð' => 'd',
		'è' => 'e',
		'é' => 'e',
		'ê' => 'e',
		'ë' => 'e',
		'ē' => 'e',
		'ĕ' => 'e',
		'ė' => 'e',
		'ę' => 'e',
		'ě' => 'e',
		'ğ' => 'g',
		'ĝ' => 'g',
		'ģ' => 'g',
		'ĥ' => 'h',
		'ħ' => 'h',
		'ì' => 'i',
		'í' => 'i',
		'î' => 'i',
		'ï' => 'i',
		'ī' => 'i',
		'ĩ' => 'i',
		'į' => 'i',
		'ı' => 'i',
		'ĵ' => 'j',
		'ķ' => 'k',
		'ĺ' => 'l',
		'ļ' => 'l',
		'ľ' => 'l',
		'ł' => 'l',
		'ñ' => 'n',
		'ń' => 'n',
		'ņ' => 'n',
		'ň' => 'n',
		'ò' => 'o',
		'ó' => 'o',
		'ô' => 'o',
		'õ' => 'o',
		'ö' => 'o',
		'ø' => 'o',
		'ō' => 'o',
		'ő' => 'o',
		'œ' => 'oe',
		'ŕ' => 'r',
		'ř' => 'r',
		'ś' => 's',
		'ŝ' => 's',
		'ş' => 's',
		'š' => 's',
		'ș' => 's',
		'ß' => 'ss',
		'ť' => 't',
		'ţ' => 't',
		'ț' => 't',
		'þ' => 'th',
		'ù' => 'u',
		'ú' => 'u',
		'û' => 'u',
		'ü' => 'u',
		'ū' => 'u',
		'ů' => 'u',
		'ű' => 'u',
		'ũ' => 'u',
		'ų' => 'u',
		'ŵ' => 'w',
		'ý' => 'y',
		'ÿ' => 'y',
		'ŷ' => 'y',
		'ź' => 'z',
		'ż' => 'z',
		'ž' => 'z',
	);

	/**
	 * The comparable form of a name: lower case ASCII words without
	 * punctuation, connectors or legal forms. A name that is nothing but legal
	 * forms keeps them.
	 */
	public static function normalise( string $name ): string {
		return implode( ' ', self::words( $name, false ) );
	}

	/**
	 * @param string|null $registered The name the registry returned.
	 */
	public static function matches( string $typed, ?string $registered ): bool {
		if ( null === $registered ) {
			return true;
		}
		$typed_text      = self::fold( $typed );
		$registered_text = self::fold( $registered );
		if ( self::is_placeholder( $registered_text ) || self::is_placeholder( $typed_text ) ) {
			return true;
		}
		if ( self::has_unfoldable( $typed_text ) !== self::has_unfoldable( $registered_text ) ) {
			return true;
		}

		foreach ( array( false, true ) as $split_dots ) {
			foreach ( array( false, true ) as $typed_expanded ) {
				foreach ( array( false, true ) as $registered_expanded ) {
					$a = self::words( $typed, $split_dots, true, $typed_expanded );
					$b = self::words( $registered, $split_dots, true, $registered_expanded );
					if ( array() === $a || array() === $b ) {
						return true;
					}
					if ( implode( '', $a ) === implode( '', $b ) || array() === array_diff( $a, $b ) || array() === array_diff( $b, $a ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * @param bool $split_dots Treat a dot as a word break instead of dropping it.
	 * @param bool $strict     Return no words for a name that is only legal forms.
	 * @param bool $expanded   Spell umlauts and Nordic letters the way registries write them without the letter (ä as ae, å as aa).
	 * @return string[]
	 */
	private static function words( string $name, bool $split_dots, bool $strict = false, bool $expanded = false ): array {
		$text = self::fold( $name, $expanded );
		$text = str_replace( array( '&', "'", '`', "\u{2019}", "\u{2018}" ), array( ' and ', '', '', '', '' ), $text );
		$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $split_dots ? str_replace( '.', ' ', $text ) : str_replace( '.', '', $text ) );
		$text = self::join_single_letters( ' ' . trim( $text ) . ' ' );

		$stripped = $text;
		foreach ( self::LEGAL_PHRASES as $phrase ) {
			$stripped = str_replace( ' ' . $phrase . ' ', ' ', $stripped );
		}
		$all   = array_values(
			array_filter(
				explode( ' ', trim( $stripped ) ),
				static function ( string $w ): bool {
					return '' !== $w;
				}
			)
		);
		$words = array_values( array_diff( $all, self::LEGAL_FORMS, self::CONNECTORS ) );
		if ( array() !== $words ) {
			return $words;
		}
		if ( $strict ) {
			return array();
		}
		return array_values(
			array_filter(
				explode( ' ', trim( $text ) ),
				static function ( string $w ): bool {
					return '' !== $w;
				}
			)
		);
	}

	/**
	 * "s a" and "s r o" are the letters of an abbreviation once the dots are gone.
	 */
	private static function join_single_letters( string $text ): string {
		return (string) preg_replace_callback(
			'/ (?:\p{L} ){2,}/u',
			static function ( array $m ): string {
				return ' ' . str_replace( ' ', '', trim( $m[0] ) ) . ' ';
			},
			$text
		);
	}

	private static function fold( string $name, bool $expanded = false ): string {
		$lower = mb_strtolower( trim( $name ), 'UTF-8' );
		return strtr( $expanded ? strtr( $lower, self::EXPAND ) : $lower, self::FOLD );
	}

	private static function has_unfoldable( string $folded ): bool {
		return 1 === preg_match( '/[^\x00-\x7F]/', (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $folded ) );
	}

	private static function is_placeholder( string $folded ): bool {
		$plain = trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $folded ) );
		return '' === $plain || in_array( $plain, self::PLACEHOLDERS, true );
	}
}
