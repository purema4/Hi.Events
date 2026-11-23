import {t} from "@lingui/macro";
import {Badge, Button, Table as MantineTable, Text} from "@mantine/core";
import {IconBan, IconCopy, IconPlus} from "@tabler/icons-react";
import {useClipboard} from "@mantine/hooks";
import {useParams} from "react-router";
import {CreateButton} from "../CreateButton";
import {NoResultsSplash} from "../NoResultsSplash";
import {Table, TableHead} from "../Table";
import {ActionMenu} from "../ActionMenu";
import {SaleLink, SaleLinkStatus} from "../../../types.ts";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useRevokeSaleLink} from "../../../mutations/useRevokeSaleLink.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../utilites/confirmationDialog.tsx";
import {formatCurrency} from "../../../utilites/currency.ts";
import {prettyDate} from "../../../utilites/dates.ts";
import {saleLinkUrl} from "../../../utilites/urlHelper.ts";
import classes from "./SaleLinkTable.module.scss";

interface SaleLinkTableProps {
    saleLinks: SaleLink[];
    openCreateModal: () => void;
}

const statusColor: Record<SaleLinkStatus, string> = {
    ACTIVE: 'green',
    USED: 'blue',
    EXPIRED: 'gray',
    REVOKED: 'red',
};

const statusLabel = (status: SaleLinkStatus): string => {
    switch (status) {
        case 'ACTIVE':
            return t`Active`;
        case 'USED':
            return t`Used`;
        case 'EXPIRED':
            return t`Expired`;
        case 'REVOKED':
            return t`Revoked`;
    }
};

export const SaleLinkTable = ({saleLinks, openCreateModal}: SaleLinkTableProps) => {
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const clipboard = useClipboard();
    const revokeMutation = useRevokeSaleLink();

    const copyLink = (saleLink: SaleLink) => {
        clipboard.copy(saleLinkUrl(saleLink.event_id, saleLink.token));
        showSuccess(t`Link copied`);
    };

    const handleRevoke = (saleLink: SaleLink) => {
        revokeMutation.mutate({eventId, saleLinkId: saleLink.id}, {
            onSuccess: () => showSuccess(t`Sale link revoked`),
            onError: (error: any) => showError(error?.response?.data?.message || t`Failed to revoke sale link`),
        });
    };

    if (saleLinks.length === 0) {
        return (
            <NoResultsSplash
                heading={t`No sale links yet`}
                imageHref={'/blank-slate/promo-codes.svg'}
                subHeading={(
                    <>
                        <p>
                            {t`A sale link is a private checkout link for specific tickets at a price you set. Send it by email or message, and the buyer pays through the normal checkout.`}
                        </p>
                        <CreateButton
                            size={'xs'}
                            leftSection={<IconPlus/>}
                            color={'green'}
                            onClick={openCreateModal}
                        >
                            {t`Create Sale Link`}
                        </CreateButton>
                    </>
                )}
            />
        );
    }

    const currency = event?.currency;

    return (
        <Table>
            <TableHead>
                <MantineTable.Tr>
                    <MantineTable.Th>{t`Tickets`}</MantineTable.Th>
                    <MantineTable.Th>{t`Status`}</MantineTable.Th>
                    <MantineTable.Th>{t`Uses`}</MantineTable.Th>
                    <MantineTable.Th>{t`Details`}</MantineTable.Th>
                    <MantineTable.Th>{t`Note`}</MantineTable.Th>
                    <MantineTable.Th></MantineTable.Th>
                    <MantineTable.Th w={50}></MantineTable.Th>
                </MantineTable.Tr>
            </TableHead>
            <MantineTable.Tbody>
                {saleLinks.map((saleLink) => (
                    <MantineTable.Tr key={saleLink.id}>
                        <MantineTable.Td>
                            <div className={classes.items}>
                                {saleLink.items.map((item) => {
                                    const title = item.price_label
                                        ? `${item.product_title} (${item.price_label})`
                                        : item.product_title;
                                    const price = currency ? formatCurrency(item.unit_price, currency) : item.unit_price;
                                    return (
                                        <Text size="sm" key={item.id}>
                                            {`${item.quantity} × ${title} @ ${price}`}
                                        </Text>
                                    );
                                })}
                            </div>
                        </MantineTable.Td>
                        <MantineTable.Td>
                            <Badge color={statusColor[saleLink.status]} variant="light" size="sm" radius="md">
                                {statusLabel(saleLink.status)}
                            </Badge>
                        </MantineTable.Td>
                        <MantineTable.Td>
                            <Text size="sm">{`${saleLink.uses_count} / ${saleLink.max_uses}`}</Text>
                        </MantineTable.Td>
                        <MantineTable.Td>
                            <div className={classes.meta}>
                                <Text size="xs" c="dimmed">
                                    {saleLink.expires_at && event
                                        ? t`Expires ${prettyDate(saleLink.expires_at, event.timezone)}`
                                        : t`No expiry`}
                                </Text>
                                {saleLink.email && (
                                    <Text size="xs" c="dimmed">
                                        {saleLink.email_locked
                                            ? t`For ${saleLink.email} (locked)`
                                            : t`For ${saleLink.email}`}
                                    </Text>
                                )}
                            </div>
                        </MantineTable.Td>
                        <MantineTable.Td>
                            {saleLink.note && (
                                <Text size="xs" c="dimmed" className={classes.note} lineClamp={3}>
                                    {saleLink.note}
                                </Text>
                            )}
                        </MantineTable.Td>
                        <MantineTable.Td>
                            {saleLink.status === 'ACTIVE' && (
                                <Button
                                    size="xs"
                                    variant="light"
                                    leftSection={<IconCopy size={12}/>}
                                    onClick={() => copyLink(saleLink)}
                                    data-testid={`sale-link-copy-button-${saleLink.id}`}
                                >
                                    {t`Copy link`}
                                </Button>
                            )}
                        </MantineTable.Td>
                        <MantineTable.Td>
                            <ActionMenu
                                dataTestId={`sale-link-actions-${saleLink.id}`}
                                itemsGroups={[
                                    {
                                        label: t`Actions`,
                                        items: [
                                            {
                                                label: t`Copy link`,
                                                icon: <IconCopy size={14}/>,
                                                onClick: () => copyLink(saleLink),
                                            },
                                            {
                                                label: t`Revoke`,
                                                requiresEdit: true,
                                                visible: saleLink.status === 'ACTIVE',
                                                icon: <IconBan size={14}/>,
                                                color: 'red',
                                                dataTestId: 'sale-link-revoke-menu-item',
                                                onClick: () => confirmationDialog(
                                                    t`Revoke this sale link?`,
                                                    () => handleRevoke(saleLink),
                                                    {
                                                        destructive: true,
                                                        confirm: t`Revoke`,
                                                        description: t`The link stops working straight away. Orders already placed with it are not affected.`,
                                                    },
                                                ),
                                            },
                                        ],
                                    },
                                ]}
                            />
                        </MantineTable.Td>
                    </MantineTable.Tr>
                ))}
            </MantineTable.Tbody>
        </Table>
    );
};
