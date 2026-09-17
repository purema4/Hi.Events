import {SupportedLocales} from "../../../locales.ts";

const APPLE_ASSET_BY_LOCALE: Partial<Record<SupportedLocales, string>> = {
    de: "DE",
    el: "GR",
    es: "ES",
    fr: "FR",
    hu: "HU",
    it: "IT",
    nl: "NL",
    pl: "PL",
    pt: "PT",
    "pt-br": "PTBR",
    se: "SE",
    sk: "SK",
    tr: "TR",
    vi: "VN",
    "zh-cn": "CN",
    "zh-hk": "HK",
};

const GOOGLE_ASSET_BY_LOCALE: Partial<Record<SupportedLocales, string>> = {
    de: "de",
    el: "gr",
    es: "esES",
    fr: "frFR",
    hu: "hu",
    it: "it",
    nl: "nl",
    pl: "pl",
    pt: "pt",
    "pt-br": "pt",
    se: "se",
    sk: "sk",
    tr: "tr",
    vi: "vi",
    "zh-hk": "zhHK",
};

export const appleWalletButtonSrc = (locale: string): string => {
    const asset = APPLE_ASSET_BY_LOCALE[locale.toLowerCase() as SupportedLocales] ?? "US_UK";

    return `/images/wallet/apple/${asset}_add_to_apple_wallet.png`;
};

export const googleWalletButtonSrc = (locale: string): string => {
    const asset = GOOGLE_ASSET_BY_LOCALE[locale.toLowerCase() as SupportedLocales] ?? "enUS";

    return `/images/wallet/google/${asset}_add_to_google_wallet_wallet-button.png`;
};
