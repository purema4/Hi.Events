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

interface GoogleWalletSettingsForm {
    google_wallet_enabled: boolean;
    logo_url: string;
    hero_image_url: string;
    background_color: string;
}

export const GoogleWalletSettings = () => {
    const {organizerId} = useParams();
    const organizerSettingsQuery = useGetOrganizerSettings(organizerId);
    const updateMutation = useUpdateOrganizerSettings();
    const formErrorHandle = useFormErrorResponseHandler();

    const form = useForm<GoogleWalletSettingsForm>({
        initialValues: {
            google_wallet_enabled: false,
            logo_url: '',
            hero_image_url: '',
            background_color: '',
        }
    });

    useEffect(() => {
        if (organizerSettingsQuery?.isFetched && organizerSettingsQuery?.data) {
            const passSettings = organizerSettingsQuery.data.google_wallet_pass_settings;

            form.setValues({
                google_wallet_enabled: organizerSettingsQuery.data.google_wallet_enabled ?? false,
                logo_url: passSettings?.logo_url ?? '',
                hero_image_url: passSettings?.hero_image_url ?? '',
                background_color: passSettings?.background_color ?? '',
            });
        }
    }, [organizerSettingsQuery.isFetched]);

    const handleSubmit = (values: GoogleWalletSettingsForm) => {
        updateMutation.mutate({
            organizerSettings: {
                google_wallet_enabled: values.google_wallet_enabled,
                google_wallet_pass_settings: {
                    logo_url: values.logo_url || undefined,
                    hero_image_url: values.hero_image_url || undefined,
                    background_color: values.background_color || undefined,
                },
            },
            organizerId: organizerId,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully Updated Google Wallet Settings`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    }

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Google Wallet`}
                description={t`Let attendees add their tickets to Google Wallet. Every ticket in an order is saved with a single tap.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={organizerSettingsQuery.isLoading || updateMutation.isPending}>
                    <Switch
                        {...form.getInputProps('google_wallet_enabled', {type: 'checkbox'})}
                        label={t`Enable Google Wallet passes`}
                        description={t`Ticket emails will include one "Add to Google Wallet" button that saves every ticket in the order.`}
                    />

                    <TextInput
                        {...form.getInputProps('logo_url')}
                        mt="md"
                        label={t`Pass logo URL`}
                        description={t`Square image, at least 660x660px. Defaults to your organizer logo.`}
                        placeholder={"https://example.com/logo.png"}
                    />

                    <TextInput
                        {...form.getInputProps('hero_image_url')}
                        label={t`Pass banner URL`}
                        description={t`Banner shown across the pass, ideally 1032x812px. Defaults to your event cover image.`}
                        placeholder={"https://example.com/banner.png"}
                    />

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
                        data-testid="google-wallet-submit-button"
                    >
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
}
