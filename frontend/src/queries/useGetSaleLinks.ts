import {useQuery} from "@tanstack/react-query";
import {saleLinkClient} from "../api/sale-link.client.ts";
import {IdParam, QueryFilters} from "../types.ts";

export const GET_SALE_LINKS_QUERY_KEY = 'getSaleLinks';

export const useGetSaleLinks = (eventId: IdParam, queryFilters: QueryFilters = {}) => {
    return useQuery({
        queryKey: [GET_SALE_LINKS_QUERY_KEY, eventId, queryFilters],
        queryFn: () => saleLinkClient.all(eventId, queryFilters),
    });
};
