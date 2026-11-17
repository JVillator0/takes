/**
 * Billing page (P08) and invoice (P09) props, mirroring the resources in
 * app/Http/Resources/Billing. A money page: every amount is a server string,
 * exact (D-042); spend is by feature, never by provider (D-023).
 */

/** BillingSummaryResource. */
export type BillingSummary = {
    available: string;
    balance: string;
    onHold: string;
    spent: {
        /** "November 2026": the UTC month, bucketed by settlement date. */
        month: string;
        total: string;
        images: string;
        aiSuggestions: string;
    };
};

/** CreditPackResource: a pack from `takes.purchase_packs_usd`. */
export type CreditPack = {
    /** Opaque identifier, sent back as the purchase's `package`; never money. */
    id: string;
    /** "$10.00" */
    amount: string;
    /** "$0.00": simulated purchases carry no tax. */
    tax: string;
    /** What the customer pays, from the server; never added up on the client. */
    total: string;
};

/** App\Enums\Billing\BillingActivityKind. */
export type BillingActivityKind =
    | 'image'
    | 'ai_suggestion'
    | 'purchase'
    | 'welcome_credit'
    | 'promo_grant'
    | 'adjustment';

/** A usage line's money state (open, settled, voided usage). */
export type BillingActivityStatus = 'on_hold' | 'charged' | 'not_charged';

/** BillingActivityRowResource. */
export type BillingActivityRow = {
    key: string;
    kind: BillingActivityKind;
    status: BillingActivityStatus | null;
    /** ISO 8601, UTC. */
    occurredAt: string;
    /** "Nov 17 12:01" (UTC). */
    occurredAtLabel: string;
    /** Signed exact amount ("-$0.0740375", "+$10.00", "$0.00"); null while on hold. */
    amount: string | null;
    /** The usage's hold, exact; null for ledger lines. */
    maxCharge: string | null;
    /** Σ ledger up to this line, exact; excludes amounts on hold. */
    balance: string;
    /** The take's prompt or the suggestion's request, shortened. */
    detail: string | null;
    takeHref: string | null;
    invoice: { number: string; href: string } | null;
};

export type BillingActivityPage = {
    rows: BillingActivityRow[];
    page: number;
    hasMore: boolean;
};

/** App\Enums\Billing\InvoiceStatus. */
export type InvoiceStatus = 'paid';

/** InvoiceResource::toArray. */
export type InvoiceSummary = {
    id: string;
    number: string;
    status: InvoiceStatus;
    issuedAt: string;
    /** "Nov 17, 2026" (UTC). */
    issuedAtLabel: string;
    total: string;
    href: string;
};

/** InvoiceResource::detail: the printable invoice. */
export type InvoiceDetail = InvoiceSummary & {
    currency: string;
    billedName: string;
    billedEmail: string;
    /** Simulated payment reference, "SIM-01…". */
    reference: string;
    quantity: number;
    unitPrice: string;
    /** The purchase line, before tax. */
    lineAmount: string;
    tax: string;
};
