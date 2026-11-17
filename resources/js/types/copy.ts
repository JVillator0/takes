/** A node of the translated copy tree shared as the `copy` Inertia prop. */
export type CopyTree = {
    [key: string]: string | CopyTree;
};

/** `lang/en/takes.php` and `lang/en/billing.php`, shared once (D-057). */
export type Copy = {
    takes: CopyTree;
    billing: CopyTree;
};

export type CopyKey = `takes.${string}` | `billing.${string}`;

export type CopyReplacements = Record<string, string | number>;
