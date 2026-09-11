import {t} from "@lingui/macro";
import {Button, TextInput} from "@mantine/core";
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
        }
    });

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            form.setValues({
                google_wallet_banner_url: eventSettingsQuery.data.google_wallet_banner_url ?? '',
            });
        }
    }, [eventSettingsQuery.isFetched]);

    const handleSubmit = (values: Partial<EventSettings>) => {
        updateMutation.mutate({
            eventSettings: {
                ...values,
                google_wallet_banner_url: values.google_wallet_banner_url || undefined,
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
                description={t`Override the banner shown on this event's Google Wallet passes.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending}>
                    <TextInput
                        {...form.getInputProps('google_wallet_banner_url')}
                        label={t`Pass banner URL`}
                        description={t`Banner shown across the pass, ideally 1032x812px. Falls back to your organizer setting, then this event's cover image.`}
                        placeholder={"https://example.com/banner.png"}
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
