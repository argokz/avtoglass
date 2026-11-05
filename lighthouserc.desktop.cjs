const mobile = require('./lighthouserc.json');

module.exports = {
    ci: {
        ...mobile.ci,
        collect: {
            ...mobile.ci.collect,
            settings: {
                preset: 'desktop',
                formFactor: 'desktop',
                screenEmulation: { mobile: false, width: 1365, height: 900, deviceScaleFactor: 1, disabled: false },
            },
        },
    },
};
