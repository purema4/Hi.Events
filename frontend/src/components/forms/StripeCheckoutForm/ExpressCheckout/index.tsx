import {useState} from "react";
import {ExpressCheckoutElement} from "@stripe/react-stripe-js";
import * as stripeJs from "@stripe/stripe-js";
import {t} from "@lingui/macro";
import classes from "./ExpressCheckout.module.scss";

interface ExpressCheckoutProps {
    isDarkTheme: boolean;
    onConfirm: () => Promise<void>;
}

export const ExpressCheckout = ({isDarkTheme, onConfirm}: ExpressCheckoutProps) => {
    const [isAvailable, setIsAvailable] = useState(false);

    const options: stripeJs.StripeExpressCheckoutElementOptions = {
        buttonHeight: 48,
        buttonTheme: {
            applePay: isDarkTheme ? 'white' : 'black',
            googlePay: isDarkTheme ? 'white' : 'black',
        },
        buttonType: {
            applePay: 'buy',
            googlePay: 'buy',
        },
        layout: {
            maxColumns: 2,
            maxRows: 2,
            overflow: 'auto',
        },
    };

    return (
        <div className={isAvailable ? classes.expressCheckout : undefined}>
            <ExpressCheckoutElement
                options={options}
                onReady={({availablePaymentMethods}) => setIsAvailable(!!availablePaymentMethods)}
                onLoadError={() => setIsAvailable(false)}
                onConfirm={onConfirm}
            />

            {isAvailable && (
                <div className={classes.divider}>
                    <span>{t`Or pay with card`}</span>
                </div>
            )}
        </div>
    );
}
