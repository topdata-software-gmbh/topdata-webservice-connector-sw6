import template from './topdata-connector-test-connection.html.twig';

const { Mixin } = Shopware;

Shopware.Component.register('topdata-connector-test-connection', {
    template: template,
    mixins: [
        Mixin.getByName('notification')
    ],
    
    inject: ['systemConfigApiService', 'TopdataApiCredentialsService'],
    
    data() {
        return {
            isLoading: false,
            processSuccess: false,
            demoSuccess: false
        };
    },
    
    methods: {
        onClickTest() {
            this.isLoading = true;
            
            this.TopdataApiCredentialsService.testApiCredentials().then((response) => {
                const credentialsValid = response.credentialsValid;
//                console.log(response);
                if (credentialsValid === "yes") {
                    let title = this.$tc('topdata-connector.testSuccessHeader');
                    let message = this.$tc('topdata-connector.testSuccessText');
                    this.createNotificationSuccess({
                        title,
                        message
                    });
                    this.processSuccess = true;
                } else {
                    let title = this.$tc('topdata-connector.testFailHeader');
                    let message = response.additionalData ? response.additionalData : this.$tc('topdata-connector.testFailText');
                    this.createNotificationError({
                        title,
                        message
                    });
                }
                this.isLoading = false;
            }).catch((errorResponse) => {
//                    console.log(errorResponse);
                    let title = this.$tc('topdata-connector.testFailHeader');
                    let message = this.$tc('topdata-connector.testFailText');
                    this.createNotificationError({
                        title,
                        message
                    });
                    this.isLoading = false;
                    this.processSuccess = false;
            });
            
            
        },

        testFinish() {
            this.processSuccess = false;
        },
        
        onClickDemo() {
            this.isLoading = true;
            
            this.TopdataApiCredentialsService.installDemoData().then((response) => {
//                console.log(response);
                if (response.success) {
                    let title = 'Install success';
                    let message = response.additionalInfo ? response.additionalInfo : 'Everything ok...';
                    this.createNotificationSuccess({
                        title,
                        message
                    });
                    this.demoSuccess = true;
                } else {
                    let title = 'Demo data failed';
                    let message = response.additionalInfo ? response.additionalInfo : 'Something went wrong...';
                    this.createNotificationError({
                        title,
                        message
                    });
                }
                this.isLoading = false;
            }).catch((errorResponse) => {
//                    console.log(errorResponse);
                    let title = 'Test Error';
                    let message = 'something went wrong';
                    this.createNotificationError({
                        title,
                        message
                    });
                    this.isLoading = false;
                    this.demoSuccess = false;
            });
            
            
        },

        demoFinish() {
            this.demoSuccess = false;
        }
    }
});