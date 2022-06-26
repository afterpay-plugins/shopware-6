const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.override('sw-entity-multi-select', {

    methods: {
        loadData() {
            if (this.$attrs.stateMachine && this.$attrs.entity === 'state_machine_state') {
                this.criteria.addAssociation('stateMachine');
                this.criteria.addFilter(Criteria.equals('state_machine_state.stateMachine.technicalName', this.$attrs.stateMachine + '.state'));
            }

            return this.$super('loadData');
        },
    }

});