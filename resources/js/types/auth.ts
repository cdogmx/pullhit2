export type User = {
    id: number;
    name: string;
    /** Public handle, shown across the site in place of the real name. */
    username?: string | null;
    email: string;
    avatar?: string;
    bio?: string | null;
    location?: string | null;
    website?: string | null;
    x_handle?: string | null;
    instagram_handle?: string | null;
    /** 'free' | 'collector' | 'guru'. */
    membership_tier?: string;
    is_admin?: boolean;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
