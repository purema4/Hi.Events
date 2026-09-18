import {t} from "@lingui/macro";
import {useLingui} from "@lingui/react";
import {useEffect, useState} from "react";
import {Skeleton} from "@mantine/core";
import {IconDeviceMobile} from "@tabler/icons-react";
import {appleWalletButtonSrc, googleWalletButtonSrc} from "./walletButtonAssets.ts";
import classes from './WalletPassCTA.module.scss';

interface WalletPassCTAProps {
    appleWalletPassUrl?: string | null;
    googleWalletSaveUrl?: string | null;
    isLoading: boolean;
}

export const WalletPassCTA = ({appleWalletPassUrl, googleWalletSaveUrl, isLoading}: WalletPassCTAProps) => {
    const {i18n} = useLingui();
    const [preferApple, setPreferApple] = useState(false);

    useEffect(() => {
        setPreferApple(/iPhone|iPad|iPod|Macintosh/.test(window.navigator.userAgent));
    }, []);

    if (!isLoading && !appleWalletPassUrl && !googleWalletSaveUrl) {
        return null;
    }

    const appleLabel = t`Add to Apple Wallet`;
    const googleLabel = t`Add to Google Wallet`;

    const appleSkeleton = <Skeleton key="apple" height={48} width={152} radius="md"/>;
    const googleSkeleton = <Skeleton key="google" height={48} width={272} radius="xl"/>;

    const appleButton = appleWalletPassUrl && (
        <a
            key="apple"
            href={appleWalletPassUrl}
            target="_blank"
            rel="noopener noreferrer"
            className={classes.button}
            title={appleLabel}
            data-testid="apple-wallet-button"
        >
            <img src={appleWalletButtonSrc(i18n.locale)} alt={appleLabel}/>
        </a>
    );

    const googleButton = googleWalletSaveUrl && (
        <a
            key="google"
            href={googleWalletSaveUrl}
            target="_blank"
            rel="noopener noreferrer"
            className={classes.button}
            title={googleLabel}
            data-testid="google-wallet-button"
        >
            <img src={googleWalletButtonSrc(i18n.locale)} alt={googleLabel}/>
        </a>
    );

    return (
        <div className={classes.container}>
            <div className={classes.header}>
                <div className={classes.iconContainer}>
                    <IconDeviceMobile size={24}/>
                </div>
                <div className={classes.content}>
                    <span className={classes.title}>{t`Keep your tickets on your phone`}</span>
                    <span className={classes.subtitle}>{t`Add every ticket in this order to your wallet for fast entry at the door`}</span>
                </div>
            </div>
            <div className={classes.buttons}>
                {isLoading
                    ? (preferApple ? [appleSkeleton, googleSkeleton] : [googleSkeleton, appleSkeleton])
                    : (preferApple ? [appleButton, googleButton] : [googleButton, appleButton])}
            </div>
        </div>
    );
};
