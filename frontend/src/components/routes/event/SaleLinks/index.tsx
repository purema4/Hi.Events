import {t} from "@lingui/macro";
import {useParams} from "react-router";
import {useDisclosure} from "@mantine/hooks";
import {IconPlus} from "@tabler/icons-react";
import {PageBody} from "../../../common/PageBody";
import {PageTitle} from "../../../common/PageTitle";
import {CreateButton} from "../../../common/CreateButton";
import {TableSkeleton} from "../../../common/TableSkeleton";
import {ToolBar} from "../../../common/ToolBar";
import {Pagination} from "../../../common/Pagination";
import {SaleLinkTable} from "../../../common/SaleLinkTable";
import {CreateSaleLinkModal} from "../../../modals/CreateSaleLinkModal";
import {useFilterQueryParamSync} from "../../../../hooks/useFilterQueryParamSync.ts";
import {useGetSaleLinks} from "../../../../queries/useGetSaleLinks.ts";
import {QueryFilters} from "../../../../types.ts";

const SaleLinks = () => {
    const {eventId} = useParams();
    const [searchParams, setSearchParams] = useFilterQueryParamSync();
    const {data: saleLinksData} = useGetSaleLinks(eventId, searchParams as QueryFilters);
    const saleLinks = saleLinksData?.data;
    const pagination = saleLinksData?.meta;
    const [createModalOpen, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);

    return (
        <PageBody>
            <PageTitle
                subheading={t`Send someone a private link to buy specific tickets at a price you choose.`}
            >
                {t`Sale Links`}
            </PageTitle>

            <ToolBar>
                <CreateButton
                    leftSection={<IconPlus/>}
                    color={'green'}
                    data-testid="sale-link-create-button"
                    onClick={openCreateModal}
                >
                    {t`Create Sale Link`}
                </CreateButton>
            </ToolBar>

            <TableSkeleton isVisible={!saleLinks}/>

            {saleLinks && <SaleLinkTable saleLinks={saleLinks} openCreateModal={openCreateModal}/>}

            {createModalOpen && <CreateSaleLinkModal onClose={closeCreateModal}/>}

            {(!!saleLinks?.length && (pagination?.last_page || 0) > 1) && (
                <Pagination
                    value={searchParams.pageNumber}
                    onChange={(value) => setSearchParams({pageNumber: value})}
                    total={Number(pagination?.last_page)}
                />
            )}
        </PageBody>
    );
};

export default SaleLinks;
