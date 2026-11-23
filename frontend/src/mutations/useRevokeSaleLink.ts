import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {saleLinkClient} from "../api/sale-link.client.ts";
import {GET_SALE_LINKS_QUERY_KEY} from "../queries/useGetSaleLinks.ts";

export const useRevokeSaleLink = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, saleLinkId}: {
            eventId: IdParam,
            saleLinkId: IdParam,
        }) => saleLinkClient.revoke(eventId, saleLinkId),

        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_SALE_LINKS_QUERY_KEY]}),
    });
}
