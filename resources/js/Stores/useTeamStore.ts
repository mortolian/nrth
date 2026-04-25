import { defineStore } from 'pinia';

type TeamFeatureFlags = Record<string, boolean>;

type TeamState = {
    id: number | null;
    name: string;
    plan: string | null;
    features: TeamFeatureFlags;
};

export const useTeamStore = defineStore('team', {
    state: (): TeamState => ({
        id: null,
        name: '',
        plan: null,
        features: {},
    }),
    actions: {
        setTeam(payload: Partial<TeamState>) {
            this.id = payload.id ?? this.id;
            this.name = payload.name ?? this.name;
            this.plan = payload.plan ?? this.plan;
            this.features = payload.features ?? this.features;
        },
    },
});
