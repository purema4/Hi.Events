import {useQuery} from "@tanstack/react-query";
import {AxiosError} from "axios";
import {saleLinkClientPublic} from "../api/sale-link.client.ts";
import {IdParam, SaleLinkPublic} from "../types.ts";

export const GET_SALE_LINK_PUBLIC_QUERY_KEY = 'getSaleLinkPublic';

export const useGetSaleLinkPublic = (eventId: IdParam, token: string | undefined) => {
    return useQuery<SaleLinkPublic, AxiosError>({
        queryKey: [GET_SALE_LINK_PUBLIC_QUERY_KEY, eventId, token],
        queryFn: async () => {
            const {data} = await saleLinkClientPublic.findByToken(eventId, token as string);
            return data;
        },
        enabled: !!eventId && !!token,
        retry: false,
        refetchOnWindowFocus: false,
    });
};
