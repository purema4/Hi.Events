import {t} from "@lingui/macro";
import {Button, ColorInput, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useParams} from "react-router";
import {useEffect} from "react";
import {EventSettings} from "../../../../../../types.ts";
import {Card} from "../../../../../common/Card";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useUpdateEventSettings} from "../../../../../../mutations/useUpdateEventSettings.ts";
import {useGetEventSettings} from "../../../../../../queries/useGetEventSettings.ts";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";

export const GoogleWalletSettings = () => {
    const {eventId} = useParams();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const updateMutation = useUpdateEventSettings();
    const formErrorHandle = useFormErrorResponseHandler();

    const form = useForm({
        initialValues: {
            google_wallet_banner_url: '',
            google_wallet_logo_url: '',
            google_wallet_background_color: '',
        }
    });

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            form.setValues({
                google_wallet_banner_url: eventSettingsQuery.data.google_wallet_banner_url ?? '',
                google_wallet_logo_url: eventSettingsQuery.data.google_wallet_logo_url ?? '',
                google_wallet_background_color: eventSettingsQuery.data.google_wallet_background_color ?? '',
            });
        }
    }, [eventSettingsQuery.isFetched]);

    const handleSubmit = (values: Partial<EventSettings>) => {
        updateMutation.mutate({
            eventSettings: {
                ...values,
                google_wallet_banner_url: values.google_wallet_banner_url || undefined,
                google_wallet_logo_url: values.google_wallet_logo_url || undefined,
                google_wallet_background_color: values.google_wallet_background_color || undefined,
            },
            eventId: eventId,
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
                description={t`Override the branding used on this event's Google Wallet passes. Anything left blank falls back to your organizer settings.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending}>
                    <TextInput
                        {...form.getInputProps('google_wallet_logo_url')}
                        label={t`Pass logo URL`}
                        description={t`Square image, at least 660x660px. Falls back to your organizer setting, then your organizer logo.`}
                        placeholder={"https://example.com/logo.png"}
                    />

                    <TextInput
                        {...form.getInputProps('google_wallet_banner_url')}
                        label={t`Pass banner URL`}
                        description={t`Banner shown across the pass, ideally 1032x812px. Falls back to your organizer setting, then this event's cover image.`}
                        placeholder={"https://example.com/banner.png"}
                    />

                    <ColorInput
                        {...form.getInputProps('google_wallet_background_color')}
                        label={t`Pass background colour`}
                        description={t`Falls back to your organizer setting, then your homepage accent colour.`}
                        placeholder={"#8b5cf6"}
                    />

                    <Button
                        mt="md"
                        loading={updateMutation.isPending}
                        type={'submit'}
                        data-testid="event-google-wallet-submit-button"
                    >
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
}
