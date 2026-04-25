import { defineStore } from 'pinia';

type AuthUser = {
    id: number | null;
    name: string;
    email: string;
};

export const useAuthStore = defineStore('auth', {
    state: (): { user: AuthUser } => ({
        user: {
            id: null,
            name: '',
            email: '',
        },
    }),
    actions: {
        setUser(user: Partial<AuthUser>) {
            this.user = {
                id: user.id ?? null,
                name: user.name ?? '',
                email: user.email ?? '',
            };
        },
    },
});
