<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Mapping;

/**
 * SOAP enum values ↔ REST values for the enums REST spells differently; other values pass through unchanged.
 */
final class EnumMapper
{
    /** ISO 3166-1 alpha-2, plus NetSuite's IC (Canary Islands), EA (Ceuta and Melilla) and XK (Kosovo). */
    const COUNTRY = [
        '_afghanistan' => 'AF', '_alandIslands' => 'AX', '_albania' => 'AL',
        '_algeria' => 'DZ', '_americanSamoa' => 'AS', '_andorra' => 'AD',
        '_angola' => 'AO', '_anguilla' => 'AI', '_antarctica' => 'AQ',
        '_antiguaAndBarbuda' => 'AG', '_argentina' => 'AR', '_armenia' => 'AM',
        '_aruba' => 'AW', '_australia' => 'AU', '_austria' => 'AT',
        '_azerbaijan' => 'AZ', '_bahamas' => 'BS', '_bahrain' => 'BH',
        '_bangladesh' => 'BD', '_barbados' => 'BB', '_belarus' => 'BY',
        '_belgium' => 'BE', '_belize' => 'BZ', '_benin' => 'BJ',
        '_bermuda' => 'BM', '_bhutan' => 'BT', '_boliviaPlurinationalStateOf' => 'BO',
        '_bonaireSintEustatiusAndSaba' => 'BQ', '_bosniaAndHerzegovina' => 'BA', '_botswana' => 'BW',
        '_bouvetIsland' => 'BV', '_brazil' => 'BR', '_britishIndianOceanTerritory' => 'IO',
        '_bruneiDarussalam' => 'BN', '_bulgaria' => 'BG', '_burkinaFaso' => 'BF',
        '_burundi' => 'BI', '_caboVerde' => 'CV', '_cambodia' => 'KH',
        '_cameroon' => 'CM', '_canada' => 'CA', '_canaryIslands' => 'IC',
        '_caymanIslands' => 'KY', '_centralAfricanRepublic' => 'CF', '_ceutaAndMelilla' => 'EA',
        '_chad' => 'TD', '_chile' => 'CL', '_china' => 'CN',
        '_christmasIsland' => 'CX', '_cocosKeelingIslands' => 'CC', '_colombia' => 'CO',
        '_comoros' => 'KM', '_congo' => 'CG', '_congoTheDemocraticRepublicOfThe' => 'CD',
        '_cookIslands' => 'CK', '_costaRica' => 'CR', '_coteDIvoire' => 'CI',
        '_croatia' => 'HR', '_cuba' => 'CU', '_curacao' => 'CW',
        '_cyprus' => 'CY', '_czechia' => 'CZ', '_denmark' => 'DK',
        '_djibouti' => 'DJ', '_dominica' => 'DM', '_dominicanRepublic' => 'DO',
        '_ecuador' => 'EC', '_egypt' => 'EG', '_elSalvador' => 'SV',
        '_equatorialGuinea' => 'GQ', '_eritrea' => 'ER', '_estonia' => 'EE',
        '_eswatini' => 'SZ', '_ethiopia' => 'ET', '_falklandIslandsMalvinas' => 'FK',
        '_faroeIslands' => 'FO', '_fiji' => 'FJ', '_finland' => 'FI',
        '_france' => 'FR', '_frenchGuiana' => 'GF', '_frenchPolynesia' => 'PF',
        '_frenchSouthernTerritories' => 'TF', '_gabon' => 'GA', '_gambia' => 'GM',
        '_georgia' => 'GE', '_germany' => 'DE', '_ghana' => 'GH',
        '_gibraltar' => 'GI', '_greece' => 'GR', '_greenland' => 'GL',
        '_grenada' => 'GD', '_guadeloupe' => 'GP', '_guam' => 'GU',
        '_guatemala' => 'GT', '_guernsey' => 'GG', '_guinea' => 'GN',
        '_guineaBissau' => 'GW', '_guyana' => 'GY', '_haiti' => 'HT',
        '_heardIslandAndMcdonaldIslands' => 'HM', '_holySee' => 'VA', '_honduras' => 'HN',
        '_hongKong' => 'HK', '_hungary' => 'HU', '_iceland' => 'IS',
        '_india' => 'IN', '_indonesia' => 'ID', '_iranIslamicRepublicOf' => 'IR',
        '_iraq' => 'IQ', '_ireland' => 'IE', '_isleOfMan' => 'IM',
        '_israel' => 'IL', '_italy' => 'IT', '_jamaica' => 'JM',
        '_japan' => 'JP', '_jersey' => 'JE', '_jordan' => 'JO',
        '_kazakhstan' => 'KZ', '_kenya' => 'KE', '_kiribati' => 'KI',
        '_koreaTheDemocraticPeoplesRepublicOf' => 'KP', '_koreaTheRepublicOf' => 'KR', '_kosovo' => 'XK',
        '_kuwait' => 'KW', '_kyrgyzstan' => 'KG', '_laoPeoplesDemocraticRepublic' => 'LA',
        '_latvia' => 'LV', '_lebanon' => 'LB', '_lesotho' => 'LS',
        '_liberia' => 'LR', '_libya' => 'LY', '_liechtenstein' => 'LI',
        '_lithuania' => 'LT', '_luxembourg' => 'LU', '_macao' => 'MO',
        '_madagascar' => 'MG', '_malawi' => 'MW', '_malaysia' => 'MY',
        '_maldives' => 'MV', '_mali' => 'ML', '_malta' => 'MT',
        '_marshallIslands' => 'MH', '_martinique' => 'MQ', '_mauritania' => 'MR',
        '_mauritius' => 'MU', '_mayotte' => 'YT', '_mexico' => 'MX',
        '_micronesiaFederatedStatesOf' => 'FM', '_moldovaTheRepublicOf' => 'MD', '_monaco' => 'MC',
        '_mongolia' => 'MN', '_montenegro' => 'ME', '_montserrat' => 'MS',
        '_morocco' => 'MA', '_mozambique' => 'MZ', '_myanmar' => 'MM',
        '_namibia' => 'NA', '_nauru' => 'NR', '_nepal' => 'NP',
        '_netherlands' => 'NL', '_newCaledonia' => 'NC', '_newZealand' => 'NZ',
        '_nicaragua' => 'NI', '_niger' => 'NE', '_nigeria' => 'NG',
        '_niue' => 'NU', '_norfolkIsland' => 'NF', '_northernMarianaIslands' => 'MP',
        '_northMacedonia' => 'MK', '_norway' => 'NO', '_oman' => 'OM',
        '_pakistan' => 'PK', '_palau' => 'PW', '_palestineStateOf' => 'PS',
        '_panama' => 'PA', '_papuaNewGuinea' => 'PG', '_paraguay' => 'PY',
        '_peru' => 'PE', '_philippines' => 'PH', '_pitcairn' => 'PN',
        '_poland' => 'PL', '_portugal' => 'PT', '_puertoRico' => 'PR',
        '_qatar' => 'QA', '_reunion' => 'RE', '_romania' => 'RO',
        '_russianFederation' => 'RU', '_rwanda' => 'RW', '_saintBarthelemy' => 'BL',
        '_saintHelenaAscensionAndTristanDaCunha' => 'SH', '_saintKittsAndNevis' => 'KN', '_saintLucia' => 'LC',
        '_saintMartinFrenchPart' => 'MF', '_saintPierreAndMiquelon' => 'PM', '_saintVincentAndTheGrenadines' => 'VC',
        '_samoa' => 'WS', '_sanMarino' => 'SM', '_saoTomeAndPrincipe' => 'ST',
        '_saudiArabia' => 'SA', '_senegal' => 'SN', '_serbia' => 'RS',
        '_seychelles' => 'SC', '_sierraLeone' => 'SL', '_singapore' => 'SG',
        '_sintMaartenDutchPart' => 'SX', '_slovakia' => 'SK', '_slovenia' => 'SI',
        '_solomonIslands' => 'SB', '_somalia' => 'SO', '_southAfrica' => 'ZA',
        '_southGeorgiaAndTheSouthSandwichIslands' => 'GS', '_southSudan' => 'SS', '_spain' => 'ES',
        '_sriLanka' => 'LK', '_sudan' => 'SD', '_suriname' => 'SR',
        '_svalbardAndJanMayen' => 'SJ', '_sweden' => 'SE', '_switzerland' => 'CH',
        '_syrianArabRepublic' => 'SY', '_taiwan' => 'TW', '_tajikistan' => 'TJ',
        '_tanzaniaTheUnitedRepublicOf' => 'TZ', '_thailand' => 'TH', '_timorLeste' => 'TL',
        '_togo' => 'TG', '_tokelau' => 'TK', '_tonga' => 'TO',
        '_trinidadAndTobago' => 'TT', '_tunisia' => 'TN', '_turkiye' => 'TR',
        '_turkmenistan' => 'TM', '_turksAndCaicosIslands' => 'TC', '_tuvalu' => 'TV',
        '_uganda' => 'UG', '_ukraine' => 'UA', '_unitedArabEmirates' => 'AE',
        '_unitedKingdom' => 'GB', '_unitedStates' => 'US', '_unitedStatesMinorOutlyingIslands' => 'UM',
        '_uruguay' => 'UY', '_uzbekistan' => 'UZ', '_vanuatu' => 'VU',
        '_venezuelaBolivarianRepublicOf' => 'VE', '_vietNam' => 'VN', '_virginIslandsBritish' => 'VG',
        '_virginIslandsUS' => 'VI', '_wallisAndFutunaIslands' => 'WF', '_westernSahara' => 'EH',
        '_yemen' => 'YE', '_zambia' => 'ZM', '_zimbabwe' => 'ZW',
    ];

    const SALES_ORDER_ORDER_STATUS = [
        '_pendingApproval' => 'A', '_pendingFulfillment' => 'B', '_cancelled' => 'C', '_partiallyFulfilled' => 'D',
        '_pendingBillingPartFulfilled' => 'E', '_pendingBilling' => 'F', '_fullyBilled' => 'G', '_closed' => 'H',
    ];

    const MAPS = [
        'country' => self::COUNTRY,
        'salesorderorderstatus' => self::SALES_ORDER_ORDER_STATUS,
    ];

    /** @var array<string, array<string, string>> */
    private static $reverse = [];

    /**
     * @param string $enum the generated enum class (short or fully qualified name)
     */
    public function toRest(string $enum, string $value): string
    {
        $key = self::key($enum);
        return isset(self::MAPS[$key][$value]) ? self::MAPS[$key][$value] : $value;
    }

    /**
     * @param string $enum the generated enum class (short or fully qualified name)
     */
    public function fromRest(string $enum, string $value): string
    {
        $key = self::key($enum);
        if (!isset(self::$reverse[$key])) {
            self::$reverse[$key] = isset(self::MAPS[$key]) ? array_flip(self::MAPS[$key]) : [];
        }
        return isset(self::$reverse[$key][$value]) ? self::$reverse[$key][$value] : $value;
    }

    private static function key(string $enum): string
    {
        return strtolower(substr($enum, (int) strrpos('\\'.$enum, '\\')));
    }
}
