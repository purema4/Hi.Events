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
import {useGetAccount} from "../../../../../../queries/useGetAccount.ts";

interface WalletPassSettingsForm {
    wallet_pass_logo_url: string;
    wallet_pass_banner_url: string;
    wallet_pass_apple_strip_url: string;
    wallet_pass_background_color: string;
}

export const WalletPassSettings = () => {
    const {eventId} = useParams();
    const {data: account} = useGetAccount();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const updateMutation = useUpdateEventSettings();
    const formErrorHandle = useFormErrorResponseHandler();

    const form = useForm<WalletPassSettingsForm>({
        initialValues: {
            wallet_pass_logo_url: '',
            wallet_pass_banner_url: '',
            wallet_pass_apple_strip_url: '',
            wallet_pass_background_color: '',
        }
    });

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            form.setValues({
                wallet_pass_logo_url: eventSettingsQuery.data.wallet_pass_logo_url ?? '',
                wallet_pass_banner_url: eventSettingsQuery.data.wallet_pass_banner_url ?? '',
                wallet_pass_apple_strip_url: eventSettingsQuery.data.wallet_pass_apple_strip_url ?? '',
                wallet_pass_background_color: eventSettingsQuery.data.wallet_pass_background_color ?? '',
            });
        }
    }, [eventSettingsQuery.isFetched]);

    const handleSubmit = (values: WalletPassSettingsForm) => {
        updateMutation.mutate({
            eventSettings: {
                wallet_pass_logo_url: values.wallet_pass_logo_url || null,
                wallet_pass_banner_url: values.wallet_pass_banner_url || null,
                wallet_pass_apple_strip_url: values.wallet_pass_apple_strip_url || null,
                wallet_pass_background_color: values.wallet_pass_background_color || null,
            },
            eventId: eventId,
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
                description={t`Override the branding used on this event's Google Wallet and Apple Wallet passes. Anything left blank falls back to your organizer settings.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending}>
                    <TextInput
                        {...form.getInputProps('wallet_pass_logo_url')}
                        label={t`Pass logo URL`}
                        description={t`Square image, at least 660x660px. Falls back to your organizer setting, then your organizer logo.`}
                        placeholder={"https://example.com/logo.png"}
                    />

                    <TextInput
                        {...form.getInputProps('wallet_pass_banner_url')}
                        label={t`Pass banner URL`}
                        description={t`Wide image shown across Google Wallet passes, ideally 1032x336px. Also used on Apple Wallet passes when no strip image is set. Falls back to your organizer setting, then this event's cover image.`}
                        placeholder={"https://example.com/banner.png"}
                    />

                    {account?.is_apple_wallet_available && (
                        <TextInput
                            {...form.getInputProps('wallet_pass_apple_strip_url')}
                            label={t`Apple Wallet strip image URL`}
                            description={t`Image shown behind the event name on Apple Wallet passes, ideally 1125x294px. Falls back to your organizer setting, then the pass banner.`}
                            placeholder={"https://example.com/strip.png"}
                        />
                    )}

                    <ColorInput
                        {...form.getInputProps('wallet_pass_background_color')}
                        label={t`Pass background colour`}
                        description={t`Falls back to your organizer setting, then your homepage accent colour.`}
                        placeholder={"#8b5cf6"}
                    />

                    <Button
                        mt="md"
                        loading={updateMutation.isPending}
                        type={'submit'}
                        data-testid="event-wallet-pass-submit-button"
                    >
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
}
