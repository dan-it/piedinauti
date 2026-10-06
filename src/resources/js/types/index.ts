import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    // Inertia requires page props to be indexable.
    [key: string]: unknown;
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { status?: string | null; errore?: string | null; avviso?: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export type RuoloCodice = 'admin_globale' | 'admin_citta' | 'responsabile' | 'accompagnatore';

export interface User {
    id: number;
    name: string;
    nome: string;
    cognome: string;
    citta_id: number | null;
    citta_nome: string | null;
    ruoli: RuoloCodice[];
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
