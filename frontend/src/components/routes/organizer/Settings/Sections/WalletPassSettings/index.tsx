import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useEffect} from "react";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {Button, ColorInput, Switch, TextInput} from "@mantine/core";
import {useGetOrganizerSettings} from "../../../../../../queries/useGetOrganizerSettings.ts";
import {useUpdateOrganizerSettings} from "../../../../../../mutations/useUpdateOrganizerSettings.ts";
import {useGetAccount} from "../../../../../../queries/useGetAccount.ts";

interface WalletPassSettingsForm {
    google_wallet_enabled: boolean;
    apple_wallet_enabled: boolean;
    logo_url: string;
    banner_image_url: string;
    apple_strip_image_url: string;
    background_color: string;
}

export const WalletPassSettings = () => {
    const {organizerId} = useParams();
    const {data: account} = useGetAccount();
    const organizerSettingsQuery = useGetOrganizerSettings(organizerId);
    const updateMutation = useUpdateOrganizerSettings();
    const formErrorHandle = useFormErrorResponseHandler();

    const form = useForm<WalletPassSettingsForm>({
        initialValues: {
            google_wallet_enabled: false,
            apple_wallet_enabled: false,
            logo_url: '',
            banner_image_url: '',
            apple_strip_image_url: '',
            background_color: '',
        }
    });

    useEffect(() => {
        if (organizerSettingsQuery?.isFetched && organizerSettingsQuery?.data) {
            const passSettings = organizerSettingsQuery.data.wallet_pass_settings;

            form.setValues({
                google_wallet_enabled: organizerSettingsQuery.data.google_wallet_enabled ?? false,
                apple_wallet_enabled: organizerSettingsQuery.data.apple_wallet_enabled ?? false,
                logo_url: passSettings?.logo_url ?? '',
                banner_image_url: passSettings?.banner_image_url ?? '',
                apple_strip_image_url: passSettings?.apple_strip_image_url ?? '',
                background_color: passSettings?.background_color ?? '',
            });
        }
    }, [organizerSettingsQuery.isFetched]);

    const handleSubmit = (values: WalletPassSettingsForm) => {
        updateMutation.mutate({
            organizerSettings: {
                google_wallet_enabled: values.google_wallet_enabled,
                apple_wallet_enabled: values.apple_wallet_enabled,
                wallet_pass_settings: {
                    logo_url: values.logo_url || undefined,
                    banner_image_url: values.banner_image_url || undefined,
                    apple_strip_image_url: values.apple_strip_image_url || undefined,
                    background_color: values.background_color || undefined,
                },
            },
            organizerId: organizerId,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully Updated Wallet Pass Settings`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    }

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Wallet passes`}
                description={t`Let attendees add their tickets to Google Wallet and Apple Wallet. The branding below is used on both.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={organizerSettingsQuery.isLoading || updateMutation.isPending}>
                    {account?.is_google_wallet_available && (
                        <Switch
                            {...form.getInputProps('google_wallet_enabled', {type: 'checkbox'})}
                            label={t`Enable Google Wallet passes`}
                            description={t`Ticket emails will include one "Add to Google Wallet" button that saves every ticket in the order.`}
                        />
                    )}

                    {account?.is_apple_wallet_available && (
                        <Switch
                            {...form.getInputProps('apple_wallet_enabled', {type: 'checkbox'})}
                            mt={account?.is_google_wallet_available ? 'md' : undefined}
                            label={t`Enable Apple Wallet passes`}
                            description={t`Ticket emails will include one "Add to Apple Wallet" link that adds every ticket in the order.`}
                        />
                    )}

                    <TextInput
                        {...form.getInputProps('logo_url')}
                        mt="md"
                        label={t`Pass logo URL`}
                        description={t`Square image, at least 660x660px. Used as the pass logo and the Apple Wallet icon. Defaults to your organizer logo.`}
                        placeholder={"https://example.com/logo.png"}
                    />

                    <TextInput
                        {...form.getInputProps('banner_image_url')}
                        label={t`Pass banner URL`}
                        description={t`Wide image shown across Google Wallet passes, ideally 1032x336px. Also used on Apple Wallet passes when no strip image is set. Defaults to your event cover image.`}
                        placeholder={"https://example.com/banner.png"}
                    />

                    {account?.is_apple_wallet_available && (
                        <TextInput
                            {...form.getInputProps('apple_strip_image_url')}
                            label={t`Apple Wallet strip image URL`}
                            description={t`Image shown behind the event name on Apple Wallet passes, ideally 1125x294px. Defaults to the pass banner.`}
                            placeholder={"https://example.com/strip.png"}
                        />
                    )}

                    <ColorInput
                        {...form.getInputProps('background_color')}
                        label={t`Pass background colour`}
                        description={t`Defaults to your homepage accent colour when left blank.`}
                        placeholder={"#8b5cf6"}
                    />

                    <Button
                        mt="md"
                        loading={updateMutation.isPending}
                        type={'submit'}
                        data-testid="wallet-pass-submit-button"
                    >
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
}
