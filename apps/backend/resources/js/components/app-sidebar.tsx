import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { FileText, LayoutGrid, ToggleRight, Users } from 'lucide-react';
import AppLogo from './app-logo';

const getMainNavItems = (isAdmin: boolean): NavItem[] => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    // Add admin navigation items
    if (isAdmin) {
        items.push(
            {
                title: 'Users',
                href: '/admin/users',
                icon: Users,
            },
            {
                title: 'Feature Flags',
                href: '/admin/feature-flags',
                icon: ToggleRight,
            },
            {
                title: 'Audit Logs',
                href: '/admin/audit-logs',
                icon: FileText,
            },
        );
    }

    return items;
};

export function AppSidebar() {
    const { auth } = usePage<{ auth: { user: { is_admin: boolean } } }>().props;
    const isAdmin = auth?.user?.is_admin ?? false;
    const mainNavItems = getMainNavItems(isAdmin);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
