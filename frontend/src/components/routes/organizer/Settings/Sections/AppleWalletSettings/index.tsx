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

interface AppleWalletSettingsForm {
    apple_wallet_enabled: boolean;
    logo_url: string;
    strip_image_url: string;
    background_color: string;
}

export const AppleWalletSettings = () => {
    const {organizerId} = useParams();
    const organizerSettingsQuery = useGetOrganizerSettings(organizerId);
    const updateMutation = useUpdateOrganizerSettings();
    const formErrorHandle = useFormErrorResponseHandler();

    const form = useForm<AppleWalletSettingsForm>({
        initialValues: {
            apple_wallet_enabled: false,
            logo_url: '',
            strip_image_url: '',
            background_color: '',
        }
    });

    useEffect(() => {
        if (organizerSettingsQuery?.isFetched && organizerSettingsQuery?.data) {
            const passSettings = organizerSettingsQuery.data.apple_wallet_pass_settings;

            form.setValues({
                apple_wallet_enabled: organizerSettingsQuery.data.apple_wallet_enabled ?? false,
                logo_url: passSettings?.logo_url ?? '',
                strip_image_url: passSettings?.strip_image_url ?? '',
                background_color: passSettings?.background_color ?? '',
            });
        }
    }, [organizerSettingsQuery.isFetched]);

    const handleSubmit = (values: AppleWalletSettingsForm) => {
        updateMutation.mutate({
            organizerSettings: {
                apple_wallet_enabled: values.apple_wallet_enabled,
                apple_wallet_pass_settings: {
                    logo_url: values.logo_url || undefined,
                    strip_image_url: values.strip_image_url || undefined,
                    background_color: values.background_color || undefined,
                },
            },
            organizerId: organizerId,
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
                description={t`Let attendees add their tickets to Apple Wallet. Every ticket in an order is added with a single tap.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={organizerSettingsQuery.isLoading || updateMutation.isPending}>
                    <Switch
                        {...form.getInputProps('apple_wallet_enabled', {type: 'checkbox'})}
                        label={t`Enable Apple Wallet passes`}
                        description={t`Ticket emails will include one "Add to Apple Wallet" link that adds every ticket in the order.`}
                    />

                    <TextInput
                        {...form.getInputProps('logo_url')}
                        mt="md"
                        label={t`Pass logo URL`}
                        description={t`Shown at the top of the pass and used as its icon. Wide images up to 480x150px work best. Defaults to your organizer logo.`}
                        placeholder={"https://example.com/logo.png"}
                    />

                    <TextInput
                        {...form.getInputProps('strip_image_url')}
                        label={t`Pass strip image URL`}
                        description={t`Shown behind the event name, ideally 1125x294px. Defaults to your event cover image.`}
                        placeholder={"https://example.com/strip.png"}
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
                        data-testid="apple-wallet-submit-button"
                    >
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
}
