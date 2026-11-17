/** A node of the translated copy tree shared as the `copy` Inertia prop. */
export type CopyTree = {
    [key: string]: string | CopyTree;
};

/** `lang/en/{takes,billing,landing}.php`, shared once (D-057). */
export type Copy = {
    takes: CopyTree;
    billing: CopyTree;
    landing: CopyTree;
};

export type CopyKey =
    | `takes.${string}`
    | `billing.${string}`
    | `landing.${string}`;

export type CopyReplacements = Record<string, string | number>;
