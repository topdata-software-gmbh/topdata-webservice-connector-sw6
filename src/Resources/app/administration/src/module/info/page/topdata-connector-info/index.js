import template from './info.html.twig';

const { Mixin } = Shopware;

Shopware.Component.register('topdata-connector-info', {
    template:template,
    
    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    mixins: [
        Mixin.getByName('notification')
    ],
    
    inject: ['systemConfigApiService', 'TopdataApiCredentialsService'],
    
    data() {
        return {
            isLoading: false,
            processSuccess: false,
            demoSuccess: false,
            config: {},
            pluginsEnabled: {
                TopdataConnectorSW6: false,
                TopdataTopFinderProSW6: false,
                TopdataTopFeedSW6: false,
                TopdataColors: false
            },
            availableBrands: [],
            brandsLoading: true,
            brandsPrimary: []
            
        };
    },
    
    computed: {
        
    },

    created() {
        this.onGetActivePlugins();
        this.loadBrands();
    },
    
    methods: {
        onGetActivePlugins() {
            var globThis = this;
            this.TopdataApiCredentialsService.getActivePlugins().then((response) => {
//                console.log(response);
                var activePlugins = response.activePlugins;
                for (let [key, value] of Object.entries(activePlugins)) {
//                    console.log(`${key}: ${value}`);
                    globThis.pluginsEnabled[value] = true;
                }
            }).catch((errorResponse) => {
                console.log(errorResponse);
            });
        },
        
        onChangeBrandsPrimary(value) {
            this.brandsPrimary = value;
//            console.log(value);
        },
        
        loadBrands() {
            this.brandsLoading = true;
            this.TopdataApiCredentialsService.loadBrands().then((response) => {
                var globThis = this;
//                console.log(response);
                if(response.brandsCount > 0) {
                    var allBrands = response.brands;
                    for (let [key, value] of Object.entries(allBrands)) {
                        globThis.availableBrands.push({ value: key, label: value });
                    }
                }
                if(response.primaryCount > 0) {
                    var primaryBrands = response.primary;
                    for (let [key, value] of Object.entries(primaryBrands)) {
                        globThis.brandsPrimary.push(key);
                    }
                }
                this.brandsLoading = false;
            }).catch((errorResponse) => {
//                console.log(errorResponse);
                this.brandsLoading = false;
            });
            
        },
        
        onSavePrimaryBrands() {
            this.brandsLoading = true;
            this.TopdataApiCredentialsService.savePrimaryBrands(this.brandsPrimary).then((response) => {
//                console.log(response);
                this.brandsLoading = false;
            }).catch((errorResponse) => {
//                console.log(errorResponse);
                this.brandsLoading = false;
            });
        },

        onClickTest() {
            this.isLoading = true;
            
            this.TopdataApiCredentialsService.testApiCredentials().then((response) => {
                const credentialsValid = response.credentialsValid;
//                console.log(response);
                if (credentialsValid === "yes") {
                    let title = 'Test Success';
                    let message = 'We connected to the server!';
                    this.createNotificationSuccess({
                        title,
                        message
                    });
                    this.processSuccess = true;
                } else {
                    let title = 'Connection Test Error';
                    let message = response.additionalData ? response.additionalData : 'Something went wrong...';
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
                    this.processSuccess = false;
            });
            
            
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
        },

        testFinish() {
            this.processSuccess = false;
        }
    }

});
