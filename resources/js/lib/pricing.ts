import type { PricingConfig } from '@/types';

export const NANODOLLARS_PER_USD = 1_000_000_000;
const MICRO_NANO_SCALE = 1_000_000n;
const VARIANCE_SCALE = 1_000_000n;

export interface LiveCostEstimate {
    estimatedTextTokens: number;
    minTextTokens: number;
    maxTextTokens: number;
    refImageCount: number;
    inputImageTokens: number;
    outputTokens: number;
    minReasoningTokens: number;
    midpointReasoningTokens: number;
    maxReasoningTokens: number;
    minProviderCostNano: number;
    expectedProviderCostNano: number;
    maxProviderCostNano: number;
    minUserCostNano: number;
    expectedUserCostNano: number;
    maxUserCostNano: number;
    markupNano: number;
}

function parseDecimalScaled(value: string, scaleDigits: number): bigint {
    const trimmed = value.trim();
    if (!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test(trimmed)) {
        return 0n;
    }

    const isNegative = trimmed.startsWith('-');
    const unsigned = trimmed.replace(/^[+-]/, '');
    const [rawInt = '0', rawFrac = ''] = unsigned.split('.');
    const intPart = rawInt === '' ? '0' : rawInt;
    const paddedFrac = rawFrac.padEnd(scaleDigits, '0').slice(0, scaleDigits);
    const parsed = BigInt(`${intPart}${paddedFrac}`);

    return isNegative ? -parsed : parsed;
}

function roundHalfAwayFromZero(numerator: bigint, divisor: bigint): bigint {
    if (numerator === 0n) {
        return 0n;
    }

    const isNegative = numerator < 0n;
    const absNumerator = isNegative ? -numerator : numerator;
    const rounded = (absNumerator + divisor / 2n) / divisor;

    return isNegative ? -rounded : rounded;
}

export function nanoToUsdString(nano: number): string {
    const intNano = BigInt(Math.trunc(nano));
    const isNegative = intNano < 0n;
    const abs = isNegative ? -intNano : intNano;
    const whole = (abs / BigInt(NANODOLLARS_PER_USD)).toString();
    const frac = (abs % BigInt(NANODOLLARS_PER_USD)).toString().padStart(9, '0');

    return `${isNegative ? '-' : ''}${whole}.${frac}`;
}

export function formatSignedUsd(nano: number, usd: string): string {
    if (nano === 0) {
        return `$${usd}`;
    }

    if (nano < 0) {
        const unsigned = usd.startsWith('-') ? usd.slice(1) : usd;
        return `-$${unsigned}`;
    }

    return `+$${usd}`;
}

export function formatUsdDisplay(nano: number, precision = 4): string {
    const safePrecision = Math.max(0, Math.trunc(precision));
    const intNano = BigInt(Math.trunc(nano));

    let roundedUnits: bigint;
    if (safePrecision <= 9) {
        const step = 10n ** BigInt(9 - safePrecision);
        roundedUnits = roundHalfAwayFromZero(intNano, step);
    } else {
        roundedUnits = intNano * 10n ** BigInt(safePrecision - 9);
    }

    const isNegative = roundedUnits < 0n;
    const absUnits = isNegative ? -roundedUnits : roundedUnits;
    const divisor = 10n ** BigInt(safePrecision);
    const intPart = (absUnits / divisor).toString();
    const groupedInteger = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    if (safePrecision === 0) {
        return `${isNegative ? '-' : ''}$${groupedInteger}`;
    }

    const fracPart = (absUnits % divisor).toString().padStart(safePrecision, '0');
    const minDecimals = Math.min(2, safePrecision);
    const baseDecimals = fracPart.slice(0, minDecimals);
    const extraDecimals = fracPart.slice(minDecimals).replace(/0+$/, '');

    return `${isNegative ? '-' : ''}$${groupedInteger}.${baseDecimals}${extraDecimals}`;
}

export function applyMarkup(providerCostNano: number, markupPercent = 25): number {
    const numerator = BigInt(Math.trunc(providerCostNano)) * BigInt(100 + markupPercent);

    return Number(roundHalfAwayFromZero(numerator, 100n));
}

export function formatDateTime(isoString: string | null): string {
    if (!isoString) {
        return '-';
    }

    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) {
        return isoString;
    }

    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

export function calculateLiveEstimate(
    prompt: string,
    size: string,
    quality: string,
    referenceImages: string[],
    pricingConfig: PricingConfig,
): LiveCostEstimate {
    const codePointLength = Array.from(prompt.trim()).length;
    const charsPerToken = Math.max(1, pricingConfig.characters_per_text_token || 4);
    const estimatedTextTokens = codePointLength === 0 ? 0 : Math.ceil(codePointLength / charsPerToken);
    const varianceScaled = parseDecimalScaled((pricingConfig.text_token_variance ?? 0.15).toFixed(6), 6);
    const minTextTokens =
        estimatedTextTokens === 0 || varianceScaled === 0n
            ? estimatedTextTokens
            : Number((BigInt(estimatedTextTokens) * (VARIANCE_SCALE - varianceScaled)) / VARIANCE_SCALE);
    const maxNumerator = BigInt(estimatedTextTokens) * (VARIANCE_SCALE + varianceScaled);
    const maxTextTokens =
        estimatedTextTokens === 0 || varianceScaled === 0n ? estimatedTextTokens : Number((maxNumerator + VARIANCE_SCALE - 1n) / VARIANCE_SCALE);

    const refImageCount = referenceImages.filter((url) => url.trim() !== '').length;
    const inputImageTokens = refImageCount * pricingConfig.tokens_per_reference_image;

    const selectedQuality = pricingConfig.qualities.find((q) => q.value === quality) ?? pricingConfig.qualities[0];
    const outputTokens = selectedQuality?.output_tokens[size] ?? 0;
    const reasoningRange = selectedQuality?.reasoning_tokens ?? { min: 0, midpoint: 0, max: 0 };

    // 1 USD per 1M tokens * 1000 = nanodollars per token; scaling USD/1M by 10^9 equals micro-nanodollars per token.
    const textRateMicroNano = parseDecimalScaled(pricingConfig.rates.input_text_tokens, 9);
    const imageRateMicroNano = parseDecimalScaled(pricingConfig.rates.input_image_tokens, 9);
    const outputRateMicroNano = parseDecimalScaled(pricingConfig.rates.output_tokens, 9);
    const reasoningRateMicroNano = parseDecimalScaled(pricingConfig.rates.reasoning_tokens, 9);

    const calcProviderNano = (textTokens: number, reasoningTokens: number): number => {
        const totalMicroNano =
            BigInt(textTokens) * textRateMicroNano +
            BigInt(inputImageTokens) * imageRateMicroNano +
            BigInt(outputTokens) * outputRateMicroNano +
            BigInt(reasoningTokens) * reasoningRateMicroNano;

        return Number(roundHalfAwayFromZero(totalMicroNano, MICRO_NANO_SCALE));
    };

    const minProviderCostNano = calcProviderNano(minTextTokens, reasoningRange.min);
    const expectedProviderCostNano = calcProviderNano(estimatedTextTokens, reasoningRange.midpoint);
    const maxProviderCostNano = calcProviderNano(maxTextTokens, reasoningRange.max);

    const markupPercent = pricingConfig.markup_percent ?? 25;
    const minUserCostNano = applyMarkup(minProviderCostNano, markupPercent);
    const expectedUserCostNano = applyMarkup(expectedProviderCostNano, markupPercent);
    const maxUserCostNano = applyMarkup(maxProviderCostNano, markupPercent);

    return {
        estimatedTextTokens,
        minTextTokens,
        maxTextTokens,
        refImageCount,
        inputImageTokens,
        outputTokens,
        minReasoningTokens: reasoningRange.min,
        midpointReasoningTokens: reasoningRange.midpoint,
        maxReasoningTokens: reasoningRange.max,
        minProviderCostNano,
        expectedProviderCostNano,
        maxProviderCostNano,
        minUserCostNano,
        expectedUserCostNano,
        maxUserCostNano,
        markupNano: expectedUserCostNano - expectedProviderCostNano,
    };
}
