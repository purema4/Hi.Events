import {useState} from "react";
import {Button, Checkbox, Stack, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {t, Trans} from "@lingui/macro";
import dayjs from "dayjs";
import {ApiKeyAbility, GenericModalProps} from "../../../types";
import {Modal} from "../../common/Modal";
import {Callout} from "../../common/Callout";
import {CopyButton} from "../../common/CopyButton";
import {useCreateApiKey} from "../../../mutations/useCreateApiKey";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler";
import {showSuccess} from "../../../utilites/notifications";

interface ApiKeyFormValues {
    name: string;
    abilities: ApiKeyAbility[];
    expires_at: string;
}

const readAbilities = (): { value: ApiKeyAbility, label: string }[] => [
    {value: 'events:read', label: t`Events, settings and occurrences`},
    {value: 'products:read', label: t`Products, categories, capacities, taxes and fees`},
    {value: 'orders:read', label: t`Orders`},
    {value: 'attendees:read', label: t`Attendees`},
    {value: 'check-in-lists:read', label: t`Check-in lists`},
    {value: 'reports:read', label: t`Reports and statistics`},
    {value: 'webhooks:read', label: t`Webhooks and their logs`},
];

const writeAbilities = (): { value: ApiKeyAbility, label: string }[] => [
    {value: 'check-ins:write', label: t`Check attendees in and out`},
];

export const CreateApiKeyModal = ({onClose}: GenericModalProps) => {
    const errorHandler = useFormErrorResponseHandler();
    const createMutation = useCreateApiKey();
    const [token, setToken] = useState<string | null>(null);

    const form = useForm<ApiKeyFormValues>({
        initialValues: {
            name: '',
            abilities: [],
            expires_at: '',
        },
        validate: {
            name: (value) => value.trim() ? null : t`Name is required`,
            abilities: (value) => value.length === 0 ? t`Select at least one ability` : null,
        },
    });

    const handleSubmit = (values: ApiKeyFormValues) => {
        createMutation.mutate({
            apiKeyData: {
                name: values.name,
                abilities: values.abilities,
                expires_at: values.expires_at ? dayjs(values.expires_at).endOf('day').toISOString() : null,
            },
        }, {
            onSuccess: (response) => {
                showSuccess(t`API key created successfully`);
                setToken(response.data.token ?? null);
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    if (token) {
        return (
            <Modal opened onClose={onClose} heading={t`Your API Key`}>
                <Callout variant="tip">
                    <Trans>This is the only time this API key will be shown. Please copy it now and store it securely.</Trans>
                </Callout>
                <TextInput
                    value={token}
                    readOnly
                    rightSection={<CopyButton value={token}/>}
                />
                <Button fullWidth mt="xl" onClick={onClose}>
                    {t`Done`}
                </Button>
            </Modal>
        );
    }

    return (
        <Modal opened onClose={onClose} heading={t`Create API Key`}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <TextInput
                    {...form.getInputProps('name')}
                    label={t`Name`}
                    description={t`To recognise the integration that uses this key`}
                    required
                />

                <Checkbox.Group
                    {...form.getInputProps('abilities')}
                    mt="md"
                    label={t`Read`}
                    description={t`The key can only read this data`}
                >
                    <Stack gap="xs" mt="xs">
                        {readAbilities().map((ability) => (
                            <Checkbox key={ability.value} value={ability.value} label={ability.label}/>
                        ))}
                    </Stack>
                </Checkbox.Group>

                <Checkbox.Group
                    value={form.values.abilities}
                    onChange={(value) => form.setFieldValue('abilities', value as ApiKeyAbility[])}
                    mt="md"
                    label={t`Write`}
                    description={t`The key can change data in your account`}
                >
                    <Stack gap="xs" mt="xs">
                        {writeAbilities().map((ability) => (
                            <Checkbox key={ability.value} value={ability.value} label={ability.label} color="orange"/>
                        ))}
                    </Stack>
                </Checkbox.Group>

                <TextInput
                    {...form.getInputProps('expires_at')}
                    mt="md"
                    type="date"
                    label={t`Expiration date`}
                    description={t`Optional. The key stops working at the end of this day.`}
                />

                <Button type="submit" fullWidth mt="xl" loading={createMutation.isPending}>
                    {t`Create API Key`}
                </Button>
            </form>
        </Modal>
    );
};
