import {useQuery} from "@tanstack/react-query";
import {AxiosError} from "axios";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam, OrderWalletPasses} from "../types.ts";

export const GET_ORDER_WALLET_PASSES_PUBLIC_QUERY_KEY = "getOrderWalletPassesPublic";

export const useGetOrderWalletPassesPublic = (
    eventId: IdParam,
    orderShortId: IdParam,
    enabled: boolean,
) => {
    return useQuery<OrderWalletPasses, AxiosError>({
        queryKey: [GET_ORDER_WALLET_PASSES_PUBLIC_QUERY_KEY, eventId, orderShortId],
        queryFn: async () => {
            const {data} = await orderClientPublic.getWalletPasses(eventId, orderShortId);
            return data;
        },
        enabled: enabled && !!eventId && !!orderShortId,
        refetchOnWindowFocus: false,
        staleTime: 5 * 60 * 1000,
        retry: false,
    });
};
