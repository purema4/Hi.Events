import {useMutation, useQueryClient} from "@tanstack/react-query";
import {CreateSaleLinkRequest, IdParam} from "../types.ts";
import {saleLinkClient} from "../api/sale-link.client.ts";
import {GET_SALE_LINKS_QUERY_KEY} from "../queries/useGetSaleLinks.ts";

export const useCreateSaleLink = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, saleLinkData}: {
            eventId: IdParam,
            saleLinkData: CreateSaleLinkRequest,
        }) => saleLinkClient.create(eventId, saleLinkData),

        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_SALE_LINKS_QUERY_KEY]}),
    });
}
