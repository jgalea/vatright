<?php

namespace Vatright\Tests\Unit\Registry;

use Vatright\Registry\CompanyName;
use PHPUnit\Framework\TestCase;

class CompanyNameTest extends TestCase {

	/**
	 * @return array<string,array{string,string}>
	 */
	public function normalisations(): array {
		return array(
			'case'                  => array( 'ACME Trading', 'acme trading' ),
			'accents'               => array( 'Café Müller Ångström', 'cafe muller angstrom' ),
			'sharp s and ligatures' => array( 'Straße Œuvre Æsir', 'strasse oeuvre aesir' ),
			'polish letters'        => array( 'Łódź Żywiec', 'lodz zywiec' ),
			'ampersand'             => array( 'Smith & Sons', 'smith sons' ),
			'and'                   => array( 'Smith and Sons', 'smith sons' ),
			'und'                   => array( 'Smith und Sons', 'smith sons' ),
			'punctuation'           => array( "O'Brien, Murphy; Co.", 'obrien murphy co' ),
			'hyphen'                => array( 'Anheuser-Busch', 'anheuser busch' ),
			'whitespace'            => array( "  Acme \t  Trading\n", 'acme trading' ),
			'gmbh'                  => array( 'Acme GmbH', 'acme' ),
			'gmbh and co kg'        => array( 'Acme GmbH & Co. KG', 'acme co' ),
			'dotted sa'             => array( 'Acme, S.A.', 'acme' ),
			'spaced sa'             => array( 'GALP ENERGIA SGPS S A', 'galp energia' ),
			'bv'                    => array( 'Booking B.V.', 'booking' ),
			'sp z oo'               => array( 'Acme sp. z o.o.', 'acme' ),
			'sro'                   => array( 'Acme s.r.o.', 'acme' ),
			'a/s'                   => array( 'Acme A/S', 'acme' ),
			'ek'                    => array( 'Acme e.K.', 'acme' ),
			'ou'                    => array( 'Acme OÜ', 'acme' ),
			'spelled out polish'    => array( 'CD PROJEKT RED SPÓŁKA AKCYJNA', 'cd projekt red' ),
			'spelled out italian'   => array( 'Eni Società per Azioni', 'eni' ),
			'spelled out dutch'     => array( 'ABN AMRO Bank Naamloze Vennootschap', 'abn amro bank' ),
			'spelled out spanish'   => array( 'Iberdrola Sociedad Anónima', 'iberdrola' ),
			'only a legal form'     => array( 'S.A.', 'sa' ),
			'nothing'               => array( '', '' ),
			'placeholder'           => array( '---', '' ),
		);
	}

	/**
	 * @dataProvider normalisations
	 */
	public function test_normalise( string $name, string $expected ): void {
		$this->assertSame( $expected, CompanyName::normalise( $name ) );
	}

	/**
	 * @return array<string,array{string,string,bool}>
	 */
	public function comparisons(): array {
		return array(
			'equal'                         => array( 'Acme Trading', 'ACME TRADING', true ),
			'legal form on one side'        => array( 'Anheuser-Busch InBev', 'NV Anheuser-Busch InBev', true ),
			'legal form on the other side'  => array( 'Anheuser-Busch InBev NV', 'Anheuser-Busch InBev', true ),
			'different legal forms'         => array( 'Acme Ltd', 'ACME GMBH', true ),
			'trade name prefix'             => array( 'Acme Trading Europe', 'Acme', true ),
			'word order'                    => array( 'Trading Acme', 'Acme Trading', true ),
			'joined and split'              => array( 'AnheuserBusch', 'Anheuser-Busch', true ),
			'domain-style name'             => array( 'Booking', 'BOOKING.COM B.V.', true ),
			'domain typed in full'          => array( 'Booking.com', 'BOOKING.COM B.V.', true ),
			'ampersand'                     => array( 'Smith & Sons', 'SMITH AND SONS LTD', true ),
			'different company'             => array( 'Totally Different Bakery', 'ABN AMRO BANK N.V.', false ),
			'same legal form only'          => array( 'Acme GmbH', 'Globex GmbH', false ),
			'shared word only on both'      => array( 'Acme Bakery', 'Globex Bakery', false ),
			'no registry name'              => array( 'Acme', '', true ),
			'dashes'                        => array( 'Acme', '---', true ),
			'null'                          => array( 'Acme', null, true ),
			'placeholder words'             => array( 'Acme', 'N/A', true ),
			'no typed name'                 => array( '', 'ACME', true ),
			'typed only a legal form'       => array( 'GmbH', 'ACME GMBH', true ),
			'registry only a legal form'    => array( 'Acme', 'S.A.', true ),
			'registry cyrillic typed latin' => array( 'Acme Trading', 'АКМЕ ТРЕЙДИНГ ЕООД', true ),
			'both cyrillic equal'           => array( 'Акме Трейдинг', 'АКМЕ ТРЕЙДИНГ ЕООД', true ),
			'both cyrillic different'       => array( 'Глобекс', 'АКМЕ ТРЕЙДИНГ ЕООД', false ),
			'umlauts as digraphs'           => array( 'Müller & Söhne GmbH', 'MUELLER UND SOEHNE GMBH', true ),
			'digraphs typed, umlauts held'  => array( 'Mueller und Soehne', 'MÜLLER & SÖHNE GMBH', true ),
			'umlauts dropped'               => array( 'Muller Sohne', 'MÜLLER UND SÖHNE GMBH', true ),
			'sharp s as ss'                 => array( 'Strasse Bau', 'STRAßE BAU GMBH', true ),
			'swedish a ring as aa'          => array( 'Aaland Design', 'ÅLAND DESIGN AB', true ),
			'swedish a ring as a'           => array( 'Aland Design', 'ÅLAND DESIGN AB', true ),
			'danish o slash as oe'          => array( 'Soerensen A/S', 'SØRENSEN A/S', true ),
			'danish o slash as o'           => array( 'Sorensen', 'SØRENSEN A/S', true ),
			'ae ligature as ae'             => array( 'Aeroe Fiskeri', 'ÆRØ FISKERI APS', true ),
			'polish l stroke'               => array( 'Lodz Handel', 'ŁÓDŹ HANDEL SP. Z O.O.', true ),
			'french et'                     => array( 'Dupont & Fils', 'DUPONT ET FILS SARL', true ),
			'spanish y'                     => array( 'Garcia y Hijos', 'GARCIA & HIJOS SL', true ),
			'italian e'                     => array( 'Rossi e Figli', 'ROSSI & FIGLI SRL', true ),
			'dutch en'                      => array( 'Jansen en Zonen', 'JANSEN & ZONEN BV', true ),
			'blue is not rewritten'         => array( 'Blue Sky', 'BLU SKY', false ),
			'blue stays blue'               => array( 'Blue Sky', 'BLUE SKY LTD', true ),
			'different umlaut name'         => array( 'Müller & Söhne', 'MEIER UND TÖCHTER GMBH', false ),
			'close german names'            => array( 'Müller Söhne', 'MÜLLER BAU GMBH', false ),
			'swedish different'             => array( 'Aland Design', 'ÖSTERMAN DESIGN AB', false ),
			'danish different'              => array( 'Sorensen', 'SØNDERGAARD A/S', false ),
			'one letter off'                => array( 'Acme', 'Acne', false ),
		);
	}

	/**
	 * @dataProvider comparisons
	 */
	public function test_matches( string $typed, ?string $registered, bool $expected ): void {
		$this->assertSame( $expected, CompanyName::matches( $typed, $registered ) );
	}

	/**
	 * What a customer might plausibly type for each recorded VIES company,
	 * keyed by the fixture's number.
	 *
	 * @return array<string,string[]>
	 */
	private static function typed_variants(): array {
		return array(
			'BE0202239951'   => array( 'Proximus', 'PROXIMUS NV', 'proximus' ),
			'BE0417497106'   => array( 'Anheuser-Busch InBev', 'anheuser busch inbev nv', 'Anheuser-Busch InBev SA/NV', 'AnheuserBusch InBev', 'ANHEUSER-BUSCH INBEV' ),
			'IT00159560366'  => array( 'Ferrari', 'Ferrari SpA', 'ferrari s.p.a', 'Ferrari Società per Azioni', 'FERRARI' ),
			'IT00743110157'  => array( 'Motorola Solutions Italia', 'Motorola Solutions Italia S.r.l.', 'motorola solutions italia societa a responsabilita limitata', 'Motorola Solutions Italia Srl' ),
			'IT00905811006'  => array( 'Eni', 'ENI S.p.A.', 'Eni Società per Azioni', 'eni spa' ),
			'NL004495445B01' => array( 'Openjongerenvereniging De Koornbeurs', 'Koornbeurs', 'De Koornbeurs', 'openjongerenvereniging de koornbeurs' ),
			'NL805734958B01' => array( 'Booking.com', 'Booking', 'Booking.com BV', 'booking.com B.V.', 'Booking com besloten vennootschap', 'BOOKING.COM' ),
			'NL820646660B01' => array( 'ABN AMRO Bank', 'Abn Amro', 'ABN AMRO Bank N.V.', 'ABN AMRO Bank Naamloze Vennootschap', 'ABN-AMRO Bank', 'abn amro bank nv' ),
			'PL7342867148'   => array( 'CD Projekt RED', 'CD Projekt Red S.A.', 'CD Projekt Red Spolka Akcyjna', 'cd projekt red sa', 'CD PROJEKT' ),
			'PL7740001454'   => array( 'Orlen', 'PKN Orlen S.A.', 'Orlen Spolka Akcyjna', 'ORLEN SA' ),
			'PT500697256'    => array( 'EDP', 'EDP SA', 'edp, s.a.', 'EDP Sociedade Anonima', 'EDP - Energias de Portugal' ),
			'PT504499777'    => array( 'Galp Energia', 'GALP ENERGIA SGPS SA', 'Galp Energia, SGPS, S.A.', 'galp energia sgps' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function recorded_names(): array {
		$names = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/fixtures/vies/sample_*.json' ) as $file ) {
			$fixture = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$body    = json_decode( (string) $fixture['response']['body'], true );
			$name    = is_array( $body ) && isset( $body['name'] ) ? trim( (string) $body['name'] ) : '';
			if ( is_array( $body ) && true === ( $body['valid'] ?? null ) ) {
				$names[ (string) preg_replace( '/^sample_|\.json$/', '', basename( $file ) ) ] = $name;
			}
		}
		return $names;
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function fixture_matches(): array {
		$names = self::recorded_names();
		$cases = array();
		foreach ( self::typed_variants() as $number => $variants ) {
			foreach ( $variants as $typed ) {
				$cases[ $number . ': ' . $typed ] = array( $typed, $names[ $number ] ?? '' );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider fixture_matches
	 */
	public function test_a_customer_typing_the_recorded_company_matches( string $typed, string $registered ): void {
		$this->assertNotSame( '', $registered, 'The fixture has a name to compare.' );
		$this->assertTrue( CompanyName::matches( $typed, $registered ), $typed . ' vs ' . $registered );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function fixture_mismatches(): array {
		$cases = array();
		foreach ( self::recorded_names() as $number => $name ) {
			if ( '' === $name || '---' === $name ) {
				continue;
			}
			$cases[ $number . ': Totally Different Bakery' ] = array( 'Totally Different Bakery', $name );
			$cases[ $number . ': Unrelated Consulting Ltd' ] = array( 'Unrelated Consulting Ltd', $name );
		}
		$cases['ENI vs Ferrari'] = array( 'Eni S.p.A.', 'FERRARI S.P.A.' );
		$cases['Orlen vs Galp']  = array( 'Orlen', 'GALP ENERGIA SGPS S A' );
		$cases['Booking vs ABN'] = array( 'Booking.com B.V.', 'ABN AMRO BANK N.V.' );
		return $cases;
	}

	/**
	 * @dataProvider fixture_mismatches
	 */
	public function test_a_different_company_does_not_match( string $typed, string $registered ): void {
		$this->assertFalse( CompanyName::matches( $typed, $registered ), $typed . ' vs ' . $registered );
	}

	public function test_every_recorded_company_with_a_name_has_typed_variants(): void {
		$with_names = array_keys(
			array_filter(
				self::recorded_names(),
				static function ( string $name ): bool {
					return '' !== $name && '---' !== $name;
				}
			)
		);
		$covered    = array_keys( self::typed_variants() );
		sort( $with_names );
		sort( $covered );
		$this->assertGreaterThanOrEqual( 12, count( $with_names ) );
		$this->assertSame( $with_names, $covered );
	}

	public function test_the_recorded_countries_without_a_name_never_refuse(): void {
		$silent = array_filter(
			self::recorded_names(),
			static function ( string $name ): bool {
				return '' === $name || '---' === $name;
			}
		);
		$this->assertNotSame( array(), $silent );
		foreach ( $silent as $number => $name ) {
			$this->assertTrue( CompanyName::matches( 'Totally Different Bakery', $name ), $number );
		}
	}
}
