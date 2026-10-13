import {Badge, Button, Group, Table, Text} from "@mantine/core";
import {IconTrash} from "@tabler/icons-react";
import {useDisclosure} from "@mantine/hooks";
import {t} from "@lingui/macro";
import {useGetApiKeys} from "../../../../../../queries/useGetApiKeys.ts";
import {useDeleteApiKey} from "../../../../../../mutations/useDeleteApiKey.ts";
import {ApiKey} from "../../../../../../types.ts";
import {Card} from "../../../../../common/Card";
import {HeadingCard} from "../../../../../common/HeadingCard";
import {LoadingMask} from "../../../../../common/LoadingMask";
import {CreateApiKeyModal} from "../../../../../modals/CreateApiKeyModal";
import {relativeDate} from "../../../../../../utilites/dates.ts";
import {confirmationDialog} from "../../../../../../utilites/confirmationDialog.tsx";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";
import classes from "./ApiKeys.module.scss";

const ApiKeys = () => {
    const apiKeysQuery = useGetApiKeys();
    const deleteMutation = useDeleteApiKey();
    const apiKeys = apiKeysQuery.data?.data;
    const [createModalOpen, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);

    const handleRevoke = (apiKey: ApiKey) => {
        confirmationDialog(t`Are you sure you want to revoke this API key? Integrations using it will stop working immediately.`, () => {
            deleteMutation.mutate({apiKeyId: apiKey.id}, {
                onSuccess: () => showSuccess(t`API key revoked`),
                onError: (error) => {
                    console.error(error);
                    showError(t`Something went wrong! Please try again`);
                },
            });
        });
    };

    const rows = apiKeys?.map((apiKey) => (
        <Table.Tr key={apiKey.id}>
            <Table.Td>
                <Text fz="sm" fw={500}>{apiKey.name}</Text>
                {apiKey.partial_token && (
                    <Text fz="xs" c="dimmed">••••{apiKey.partial_token}</Text>
                )}
            </Table.Td>
            <Table.Td>
                <Group gap={4}>
                    {apiKey.abilities.map((ability) => (
                        <Badge key={ability} variant="light" color={ability.endsWith(':write') ? 'orange' : undefined}>
                            {ability}
                        </Badge>
                    ))}
                </Group>
            </Table.Td>
            <Table.Td>
                {apiKey.created_by ? `${apiKey.created_by.first_name} ${apiKey.created_by.last_name}` : '-'}
            </Table.Td>
            <Table.Td>
                {apiKey.last_used_at ? relativeDate(apiKey.last_used_at) : t`Never`}
                {apiKey.last_used_ip && (
                    <Text fz="xs" c="dimmed">{apiKey.last_used_ip}</Text>
                )}
            </Table.Td>
            <Table.Td>
                {apiKey.expires_at ? relativeDate(apiKey.expires_at) : t`Never`}
            </Table.Td>
            <Table.Td width={'60px'}>
                <Button
                    variant={'transparent'}
                    color={'red'}
                    onClick={() => handleRevoke(apiKey)}
                    aria-label={t`Revoke`}
                    title={t`Revoke`}
                >
                    <IconTrash size={18}/>
                </Button>
            </Table.Td>
        </Table.Tr>
    ));

    return (
        <>
            <HeadingCard
                heading={t`API Keys`}
                subHeading={t`Let other applications access your account data with the abilities you choose. Keys are shown only once, when they are created.`}
                buttonText={t`Create API Key`}
                buttonAction={openCreateModal}
            />

            <Card style={{padding: 0, position: 'relative'}}>
                <LoadingMask/>
                <Table.ScrollContainer className={classes.table} minWidth={800}>
                    <Table verticalSpacing="sm">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>{t`Name`}</Table.Th>
                                <Table.Th>{t`Abilities`}</Table.Th>
                                <Table.Th>{t`Created by`}</Table.Th>
                                <Table.Th>{t`Last used`}</Table.Th>
                                <Table.Th>{t`Expires`}</Table.Th>
                                <Table.Th/>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {rows}
                            {apiKeys?.length === 0 && (
                                <Table.Tr>
                                    <Table.Td colSpan={6}>
                                        <Text fz="sm" c="dimmed">{t`No API keys yet`}</Text>
                                    </Table.Td>
                                </Table.Tr>
                            )}
                        </Table.Tbody>
                    </Table>
                </Table.ScrollContainer>
            </Card>
            {createModalOpen && <CreateApiKeyModal onClose={closeCreateModal}/>}
        </>
    );
};

export default ApiKeys;
