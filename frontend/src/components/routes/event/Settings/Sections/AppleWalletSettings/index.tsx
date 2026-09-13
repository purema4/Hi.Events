import {t} from "@lingui/macro";
import {Button, ColorInput, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useParams} from "react-router";
import {useEffect} from "react";
import {Card} from "../../../../../common/Card";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useUpdateEventSettings} from "../../../../../../mutations/useUpdateEventSettings.ts";
import {useGetEventSettings} from "../../../../../../queries/useGetEventSettings.ts";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";

interface AppleWalletSettingsForm {
    apple_wallet_logo_url: string;
    apple_wallet_strip_image_url: string;
    apple_wallet_background_color: string;
}

export const AppleWalletSettings = () => {
    const {eventId} = useParams();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const updateMutation = useUpdateEventSettings();
    const formErrorHandle = useFormErrorResponseHandler();

    const form = useForm<AppleWalletSettingsForm>({
        initialValues: {
            apple_wallet_logo_url: '',
            apple_wallet_strip_image_url: '',
            apple_wallet_background_color: '',
        }
    });

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            form.setValues({
                apple_wallet_logo_url: eventSettingsQuery.data.apple_wallet_logo_url ?? '',
                apple_wallet_strip_image_url: eventSettingsQuery.data.apple_wallet_strip_image_url ?? '',
                apple_wallet_background_color: eventSettingsQuery.data.apple_wallet_background_color ?? '',
            });
        }
    }, [eventSettingsQuery.isFetched]);

    const handleSubmit = (values: AppleWalletSettingsForm) => {
        updateMutation.mutate({
            eventSettings: {
                apple_wallet_logo_url: values.apple_wallet_logo_url || null,
                apple_wallet_strip_image_url: values.apple_wallet_strip_image_url || null,
                apple_wallet_background_color: values.apple_wallet_background_color || null,
            },
            eventId: eventId,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully Updated Apple Wallet Settings`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    }

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Apple Wallet`}
                description={t`Override the branding used on this event's Apple Wallet passes. Anything left blank falls back to your organizer settings.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending}>
                    <TextInput
                        {...form.getInputProps('apple_wallet_logo_url')}
                        label={t`Pass logo URL`}
                        description={t`Wide images up to 480x150px work best. Falls back to your organizer setting, then your organizer logo.`}
                        placeholder={"https://example.com/logo.png"}
                    />

                    <TextInput
                        {...form.getInputProps('apple_wallet_strip_image_url')}
                        label={t`Pass strip image URL`}
                        description={t`Shown behind the event name, ideally 1125x294px. Falls back to your organizer setting, then this event's cover image.`}
                        placeholder={"https://example.com/strip.png"}
                    />

                    <ColorInput
                        {...form.getInputProps('apple_wallet_background_color')}
                        label={t`Pass background colour`}
                        description={t`Falls back to your organizer setting, then your homepage accent colour.`}
                        placeholder={"#8b5cf6"}
                    />

                    <Button
                        mt="md"
                        loading={updateMutation.isPending}
                        type={'submit'}
                        data-testid="event-apple-wallet-submit-button"
                    >
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
}
